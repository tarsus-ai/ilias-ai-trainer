<?php

namespace TarsusIlias\Cron;

use TarsusIlias\Activity\Scope;
use TarsusIlias\Activity\Scoring;
use TarsusIlias\Client\ClientFactory;
use TarsusIlias\Client\ConfigurationException;
use TarsusIlias\Client\TransportException;
use TarsusIlias\Guardrail\BlockedException;
use TarsusIlias\Guardrail\Pipeline;
use TarsusIlias\Support\Ilias;
use TarsusIlias\Support\Pseudonymizer;
use TarsusIlias\Support\UsageMeter;

/**
 * Learners whose ILIAS account was deleted. ILIAS does not tell repository
 * plugins when an account goes, so the scheduled task looks for user ids
 * the plugin still holds that ILIAS no longer knows: their conversations in
 * the brain are erased, their figures deleted, and their rows in the
 * request log keep the counts but lose the user id.
 *
 * The local data goes only once the brain has erased every conversation
 * (or had none); while the brain cannot be reached, nothing is touched and
 * the next run tries again.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class DeletedAccounts
{
    /** Accounts handled per run at most (each costs one call to the brain per activity used). */
    public const BATCH = 20;

    /**
     * @param Pipeline|null $pipeline The guardrails the calls pass (tests give their own).
     * @return array{accounts: int, failed: int}
     */
    public static function clean(?Pipeline $pipeline = null): array
    {
        $done = 0;
        $failed = 0;
        $pipeline = $pipeline ?? new Pipeline();
        foreach (self::departed(self::BATCH) as $userid => $objids) {
            if (self::erase($pipeline, $userid, $objids)) {
                self::forget($userid);
                $done++;
            } else {
                $failed++;
            }
        }
        if ($failed > 0) {
            Ilias::log($failed . ' deleted account(s): the brain could not erase their conversations yet; the next run tries again');
        }
        return ['accounts' => $done, 'failed' => $failed];
    }

    /**
     * User ids the plugin holds that ILIAS no longer knows, each with the
     * activities they used.
     *
     * @return array<int, int[]>
     */
    public static function departed(int $limit): array
    {
        $db = Ilias::db();
        $found = [];
        foreach ([Scoring::TABLE, UsageMeter::TABLE] as $table) {
            $res = $db->query(
                'SELECT DISTINCT t.usr_id, t.obj_id FROM ' . $table . ' t'
                . ' LEFT JOIN usr_data u ON u.usr_id = t.usr_id'
                . ' WHERE t.usr_id > 0 AND u.usr_id IS NULL'
            );
            while ($row = $db->fetchAssoc($res)) {
                $found[(int) $row['usr_id']][(int) $row['obj_id']] = true;
            }
        }
        $departed = [];
        foreach (array_slice($found, 0, $limit, true) as $userid => $objids) {
            $departed[$userid] = array_keys($objids);
        }
        return $departed;
    }

    /**
     * Ask the brain to erase the account's conversation in every activity
     * it used. False when the brain could not be reached or refused.
     *
     * @param int[] $objids
     */
    private static function erase(Pipeline $pipeline, int $userid, array $objids): bool
    {
        $pseudonym = Pseudonymizer::pseudonymize($userid);
        foreach ($objids as $objid) {
            if ($objid <= 0) {
                continue;
            }
            $contextid = Scope::conversationContextId($objid);
            try {
                $pipeline->execute(function () use ($contextid, $pseudonym): array {
                    ClientFactory::getClient()->resetConversation($contextid, $pseudonym);
                    return [];
                });
            } catch (ConfigurationException $e) {
                // No brain is configured: there is none to reach.
                return true;
            } catch (BlockedException $e) {
                return false;
            } catch (TransportException $e) {
                // Nothing stored for this learner there: nothing to erase.
                if (!$e->says('CONTEXT DOES NOT EXIST')) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * The account's figures go; its request log rows keep their counts
     * without the user id.
     */
    private static function forget(int $userid): void
    {
        $db = Ilias::db();
        $db->manipulateF('DELETE FROM ' . Scoring::TABLE . ' WHERE usr_id = %s', ['integer'], [$userid]);
        $db->manipulateF('UPDATE ' . UsageMeter::TABLE . ' SET usr_id = 0 WHERE usr_id = %s', ['integer'], [$userid]);
    }
}
