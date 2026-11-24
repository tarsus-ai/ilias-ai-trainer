<?php

namespace TarsusIlias\Service;

use TarsusIlias\Activity\Learners;
use TarsusIlias\Activity\Scope;
use TarsusIlias\Activity\Scoring;
use TarsusIlias\Activity\Settings;
use TarsusIlias\Client\ClientFactory;
use TarsusIlias\Client\ClientInterface;
use TarsusIlias\Client\ConfigurationException;
use TarsusIlias\Client\TransportException;
use TarsusIlias\Guardrail\BlockedException;
use TarsusIlias\Guardrail\Pipeline;
use TarsusIlias\Output\Shaper;
use TarsusIlias\Support\Ilias;
use TarsusIlias\Support\Pseudonymizer;

/**
 * The teacher's side panels of a Tarsus object: the evaluation of all
 * learners, one learner's history (read only) and how that learner grew.
 *
 * The figures are Tarsus's own, read back with one call for all learners;
 * they also refresh the learning progress in ILIAS. Reading costs no
 * credits and is never refused by the monthly limit.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class TeacherApi
{
    /** Days the growth chart covers. */
    public const ANALYTICS_DAYS = 30;

    private int $objid;
    private int $containerref;
    private Settings $settings;
    private ClientInterface $client;
    private Pipeline $pipeline;

    public function __construct(int $objid, int $containerref, Settings $settings)
    {
        $this->objid = $objid;
        $this->containerref = $containerref;
        $this->settings = $settings;
        $this->client = ClientFactory::getClient();
        $this->pipeline = new Pipeline();
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function result(string $status, string $error = '', array $extra = []): array
    {
        return $extra + ['status' => $status, 'error' => $error];
    }

    /**
     * @param callable(): array<string, mixed> $call
     * @return array<string, mixed>|string The response, or the message of the failure.
     */
    private function read(callable $call, string $context)
    {
        try {
            $response = $this->pipeline->execute($call);
            return is_array($response) ? $response : [];
        } catch (BlockedException $e) {
            return Ilias::txt('guardrail_blocked_' . $e->reason);
        } catch (TransportException $e) {
            return Messages::forTransport($e, $context);
        } catch (ConfigurationException $e) {
            return Ilias::txt($e->langKey);
        }
    }

    /**
     * Every learner of the course with Tarsus's figures, their result in
     * percent, their learning progress status in ILIAS and the groups of
     * the course for the filter.
     *
     * @return array<string, mixed>
     */
    public function statistics(): array
    {
        $learners = new Learners($this->objid, $this->containerref, $this->settings);
        $userids = array_column($learners->overview()['learners'], 'userid');

        // Tarsus's figures for every learner at once; they refresh the
        // learning progress in ILIAS on the way.
        $pseudonyms = [];
        foreach ($userids as $userid) {
            $pseudonyms[Pseudonymizer::pseudonymize((int) $userid)] = (int) $userid;
        }
        $warning = '';
        if ($pseudonyms !== []) {
            $contextid = Scope::conversationContextId($this->objid);
            $response = $this->read(
                fn() => ['statistics' => $this->client->getContextStatistics($contextid, array_keys($pseudonyms))],
                'statistics'
            );
            if (is_string($response)) {
                $warning = $response;
            } else {
                foreach ((array) ($response['statistics'] ?? []) as $pseudonym => $figures) {
                    if (isset($pseudonyms[$pseudonym]) && is_array($figures)) {
                        Scoring::record($this->objid, $pseudonyms[$pseudonym], $figures, false);
                    }
                }
            }
        }

        // Read again: the overview now carries the brain's figures.
        $overview = $learners->overview();
        $groups = $this->groups();
        $rows = [];
        foreach ($overview['learners'] as $learner) {
            $userid = (int) $learner['userid'];
            $rows[] = [
                'userid' => $userid,
                'name' => trim($learner['lastname'] . ', ' . $learner['firstname'], ', '),
                'login' => (string) $learner['login'],
                'member' => (bool) $learner['member'],
                'started' => (bool) $learner['started'],
                'grade' => $learner['grade'] === null ? null : round((float) $learner['grade'], 1),
                'progress' => round((float) $learner['progress'], 1),
                'earnedxp' => round((float) $learner['earnedxp'], 2),
                'totalxp' => round((float) $learner['totalxp'], 2),
                'earnedunits' => round((float) $learner['earnedunits'], 2),
                'totalunits' => round((float) $learner['totalunits'], 2),
                'status' => (int) $learner['status'],
                'lastactivity' => (int) $learner['lastactivity'],
                'lastactivitylabel' => (int) $learner['lastactivity'] > 0 ? Ilias::date((int) $learner['lastactivity']) : '',
                'groups' => $groups['members'][$userid] ?? [],
            ];
        }

        return self::result('success', '', [
            'learners' => $rows,
            'summary' => $overview['summary'],
            'groups' => $groups['list'],
            'measurable' => Scoring::measurable($this->settings),
            'threshold' => $this->settings->completionprogress,
            'warning' => $warning,
        ]);
    }

    /**
     * The groups inside the course, with their members, for the filter.
     *
     * @return array{list: array<int, array{id: int, title: string}>, members: array<int, int[]>}
     */
    private function groups(): array
    {
        $list = [];
        $members = [];
        try {
            if (\ilObject::_lookupType($this->containerref, true) !== 'crs') {
                return ['list' => [], 'members' => []];
            }
            foreach (Ilias::tree()->getSubTree(Ilias::tree()->getNodeData($this->containerref), true, ['grp']) as $node) {
                $refid = (int) $node['ref_id'];
                if ($refid <= 0 || !Ilias::refAlive($refid)) {
                    continue;
                }
                $list[] = ['id' => $refid, 'title' => (string) $node['title']];
                foreach (\ilParticipants::getInstance($refid)->getMembers() as $userid) {
                    $members[(int) $userid][] = $refid;
                }
            }
        } catch (\Throwable $e) {
            Ilias::log('groups of the course: ' . $e->getMessage());
        }
        return ['list' => $list, 'members' => $members];
    }

    /**
     * One learner's history as Tarsus stored it, newest page first, for
     * reading only: nothing is generated, nothing is charged.
     *
     * @return array<string, mixed>
     */
    public function history(int $userid, int $offset): array
    {
        if (!$this->isLearner($userid)) {
            return self::result('error', Ilias::txt('invalid_request'));
        }
        $allowed = Scope::allowedResourceIds($this->objid, $this->containerref);
        if ($allowed === []) {
            return self::result('noscope', Ilias::txt('chat_noscope'), ['items' => [], 'hasmore' => false]);
        }
        $params = [
            'contextid' => Scope::conversationContextId($this->objid),
            'userid' => Pseudonymizer::pseudonymize($userid),
            'resourceids' => $allowed,
            'taxonomylevels' => $this->settings->taxonomylevels,
            'offset' => max(0, $offset),
            'pagesize' => LearnerApi::FEED_PAGE_SIZE,
        ];
        $response = $this->read(fn() => $this->client->retrieveFeed($params), 'history');
        if (is_string($response)) {
            return self::result('error', $response, ['items' => [], 'hasmore' => false]);
        }
        $name = \ilObjUser::_lookupName($userid);
        return self::result('success', '', LearnerApi::feedPage($response) + [
            'progress' => Shaper::progress((array) ($response['progress'] ?? [])),
            'name' => trim((string) ($name['firstname'] ?? '') . ' ' . (string) ($name['lastname'] ?? '')),
        ]);
    }

    /**
     * How one learner's experience points and learning units grew over the
     * last 30 days, in percent of all.
     *
     * @return array<string, mixed>
     */
    public function analytics(int $userid): array
    {
        if (!$this->isLearner($userid)) {
            return self::result('error', Ilias::txt('invalid_request'));
        }
        $end = time();
        $start = $end - self::ANALYTICS_DAYS * 86400;
        $contextid = Scope::conversationContextId($this->objid);
        $pseudonym = Pseudonymizer::pseudonymize($userid);
        $response = $this->read(
            fn() => $this->client->getUserAnalytics($contextid, $pseudonym, $start, $end),
            'analytics'
        );
        if (is_string($response)) {
            return self::result('error', $response);
        }
        return self::result('success', '', [
            'xp' => array_values((array) ($response['xp'] ?? [])),
            'units' => array_values((array) ($response['units'] ?? [])),
            'start' => $start,
            'end' => $end,
        ]);
    }

    /**
     * Whether a user belongs to this object's learners: a member of its
     * course or group, or someone with figures here.
     */
    private function isLearner(int $userid): bool
    {
        if ($userid <= 0 || !\ilObject::_exists($userid, false, 'usr')) {
            return false;
        }
        $learners = new Learners($this->objid, $this->containerref, $this->settings);
        return in_array($userid, $learners->members(), true) || Scoring::row($this->objid, $userid) !== null;
    }
}
