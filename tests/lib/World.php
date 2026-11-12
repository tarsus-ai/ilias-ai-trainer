<?php

/**
 * The state behind the fake ILIAS: one database, one user, a repository
 * tree with a course in it, files, permissions, collected mails and log
 * lines. Reset before every test.
 *
 * @license GPL-3.0-or-later
 */

namespace TarsusIlias\Tests;

use TarsusIlias\Activity\Settings;
use TarsusIlias\Client\ClientFactory;
use TarsusIlias\Index\Inclusions;
use TarsusIlias\Index\Reconciler;
use TarsusIlias\Index\Repository;
use TarsusIlias\Index\Status;
use TarsusIlias\Material\ResourceId;
use TarsusIlias\Service\LearnerApi;
use TarsusIlias\Support\Config;
use TarsusIlias\Support\Ilias;

final class World
{
    /** The course every test object lives in. */
    public const COURSE = 100;
    /** The Tarsus object of the tests: reference and object id. */
    public const OBJECT_REF = 110;
    public const OBJECT = 210;

    public static FakeDb $db;
    public static \ILIAS\DI\Container $dic;
    public static \ilObjUser $user;
    /** @var object|null */
    public static $ctrl = null;
    /** @var object|null */
    public static $tabs = null;
    /** @var object|null */
    public static $tpl = null;

    /** @var array<int, array{ref_id: int, obj_id: int, type: string, title: string, description: string, parent: int, trash: bool}> */
    public static array $nodes = [];
    /** @var array<string, bool> "permission:ref" => granted (default: granted). */
    public static array $permissions = [];
    /** @var array<string, array<string, mixed>> Resource id => path, mimetype, title, version. */
    public static array $files = [];
    /** @var array<int, array{to: string[], subject: string, body: string}> */
    public static array $mails = [];
    /** @var string[] */
    public static array $log = [];
    /** @var array<string, string> */
    public static array $settings = [];
    /** @var string[] Temporary files to remove with the next reset. */
    private static array $temp = [];

    /**
     * A fresh installation of the current plugin version: every database
     * step applied, nothing configured (mock mode on), one course with a
     * Tarsus object.
     */
    public static function reset(): void
    {
        global $DIC;

        foreach (self::$temp as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        self::$temp = [];

        self::$db = new FakeDb();
        self::$dic = new \ILIAS\DI\Container();
        $DIC = self::$dic;
        self::$user = new \ilObjUser(6, 'Ada Lovelace', 'en');
        self::$ctrl = class_exists(FakeCtrl::class) ? new FakeCtrl() : null;
        self::$tabs = class_exists('ilTabsGUI') ? new \ilTabsGUI() : null;
        self::$tpl = class_exists(FakeTemplate::class) ? new FakeTemplate() : null;
        self::$nodes = [];
        self::$permissions = [];
        self::$files = [];
        self::$mails = [];
        self::$log = [];
        self::$settings = ['admin_email' => 'admin@example.org'];
        $_POST = [];
        $_FILES = [];

        Config::reset();
        ClientFactory::override(null);
        Reconciler::resetSweep();
        Ilias::setPlugin(new FakePlugin());

        self::install();
        self::node(1, 1, 'root', 'Repository', 0);
        self::node(self::COURSE, 200, 'crs', 'Welding basics', 1);
        self::node(self::OBJECT_REF, self::OBJECT, 'xtar', 'Learning coach', self::COURSE);

        if (class_exists(TarsusServer::class, false)) {
            TarsusServer::clear();
        }
    }

    // ------------------------------------------------------------------
    // Database steps
    // ------------------------------------------------------------------

    /**
     * The steps of sql/dbupdate.php: number => PHP code.
     *
     * @return array<int, string>
     */
    public static function steps(): array
    {
        static $steps = null;
        if ($steps === null) {
            $steps = [];
            $source = (string) file_get_contents(PLUGIN_ROOT . '/sql/dbupdate.php');
            preg_match_all('/<#(\d+)>\s*<\?php(.*?)\?>/s', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $steps[(int) $match[1]] = $match[2];
            }
        }
        return $steps;
    }

    /**
     * Apply the database steps from..to (inclusive) as ILIAS would.
     */
    public static function install(int $from = 1, ?int $to = null): void
    {
        foreach (self::steps() as $number => $code) {
            if ($number < $from || ($to !== null && $number > $to)) {
                continue;
            }
            (static function ($ilDB, string $code): void {
                eval($code);
            })(self::$db, $code);
        }
        Config::reset();
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    /**
     * @param array<string, string|null> $values
     */
    public static function configure(array $values): void
    {
        foreach ($values as $key => $value) {
            Config::set($key, $value);
        }
    }

    public static function plan(string $plan): void
    {
        Config::set('plan', $plan);
    }

    /**
     * Leave mock mode and talk to the fake Tarsus API with a brain ID.
     */
    public static function live(string $brainid = 'brain-test-1'): void
    {
        self::configure([
            'mockmode' => '0',
            'base_url' => TarsusServer::url(),
            'api_key' => 'test-api-key',
            'brain_id' => $brainid,
        ]);
    }

    // ------------------------------------------------------------------
    // Repository
    // ------------------------------------------------------------------

    public static function node(int $refid, int $objid, string $type, string $title, int $parent, string $description = ''): void
    {
        self::$nodes[$refid] = [
            'ref_id' => $refid, 'obj_id' => $objid, 'type' => $type, 'title' => $title,
            'description' => $description, 'parent' => $parent, 'trash' => false,
        ];
    }

    /**
     * A file object in the course. Returns its resource id.
     */
    public static function file(int $refid, string $title, string $mimetype, string $content, int $parent = self::COURSE): string
    {
        $objid = $refid + 1000;
        self::node($refid, $objid, 'file', $title, $parent);
        $rid = 'rid-' . $refid;
        $path = self::tempFile($content);
        self::$files[$rid] = ['path' => $path, 'mimetype' => $mimetype, 'title' => $title, 'version' => 1];
        if (!self::$db->tableExists('file_data')) {
            self::$db->manipulate('CREATE TABLE file_data (file_id INTEGER, file_name TEXT, rid TEXT)');
        }
        self::$db->manipulate('DELETE FROM file_data WHERE file_id = ' . $objid);
        self::$db->manipulate('INSERT INTO file_data (file_id, file_name, rid) VALUES (' . $objid . ', '
            . self::$db->quote($title, 'text') . ', ' . self::$db->quote($rid, 'text') . ')');
        return ResourceId::build($parent === self::COURSE ? self::COURSE : $parent, 'file', $refid);
    }

    /**
     * A web link object with one link. Returns the link's resource id.
     */
    public static function link(int $refid, string $title, string $url): string
    {
        $objid = $refid + 1000;
        self::node($refid, $objid, 'webr', $title, self::COURSE);
        if (!self::$db->tableExists('webr_items')) {
            self::$db->manipulate('CREATE TABLE webr_items (link_id INTEGER, webr_id INTEGER, title TEXT, target TEXT, active INTEGER, internal INTEGER)');
        }
        self::$db->manipulate('INSERT INTO webr_items (link_id, webr_id, title, target, active, internal) VALUES (1, ' . $objid . ', '
            . self::$db->quote($title, 'text') . ', ' . self::$db->quote($url, 'text') . ', 1, 0)');
        return ResourceId::build(self::COURSE, 'webr', $refid, 'link1');
    }

    public static function tempFile(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xtartest');
        file_put_contents($path, $content);
        self::$temp[] = $path;
        return $path;
    }

    // ------------------------------------------------------------------
    // The Tarsus object
    // ------------------------------------------------------------------

    /**
     * The settings of the test object, online with all three modes on.
     *
     * @param array<string, mixed> $overrides Property => value.
     */
    public static function settings(array $overrides = []): Settings
    {
        $settings = new Settings(self::OBJECT);
        $settings->online = true;
        foreach ($overrides as $property => $value) {
            $settings->$property = $value;
        }
        $settings->save();
        return Settings::load(self::OBJECT);
    }

    /**
     * Put one indexed resource into the object's scope, as if a teacher
     * had indexed and included it. Returns its resource id.
     */
    public static function scope(int $refid = 120): string
    {
        $resourceid = ResourceId::build(self::COURSE, 'webr', $refid, 'link1');
        $repo = new Repository();
        $repo->ensure($resourceid, self::COURSE, ResourceId::assetId(self::COURSE), 'webr', $refid);
        self::$db->manipulate('UPDATE ' . Repository::TABLE . ' SET idx_status = ' . self::$db->quote(Status::INDEXED, 'text')
            . ' WHERE resource_id = ' . self::$db->quote($resourceid, 'text'));
        Inclusions::include(self::OBJECT, $resourceid);
        return $resourceid;
    }

    /**
     * The learner's API of the test object, with material in scope.
     *
     * @param array<string, mixed> $overrides Settings overrides.
     */
    public static function learner(array $overrides = [], bool $withscope = true): LearnerApi
    {
        $settings = self::settings($overrides);
        if ($withscope) {
            self::scope();
        }
        return new LearnerApi(self::OBJECT, 'Learning coach', 'About welding', $settings, self::COURSE);
    }

    // ------------------------------------------------------------------
    // The ledger
    // ------------------------------------------------------------------

    /**
     * Write a ledger row directly (for history a test cannot create by
     * waiting).
     */
    public static function ledger(string $operation, int $credits, ?int $created = null, int $userid = 6, int $objid = self::OBJECT): void
    {
        self::$db->insert('rep_robj_xtar_use', [
            'id' => ['integer', self::$db->nextId('rep_robj_xtar_use')],
            'container_ref' => ['integer', self::COURSE],
            'obj_id' => ['integer', $objid],
            'usr_id' => ['integer', $userid],
            'op_name' => ['text', $operation],
            'credits' => ['integer', $credits],
            'created' => ['integer', $created ?? time()],
        ]);
    }

    /**
     * The ledger as [operation, credits] pairs, oldest first.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public static function ledgerRows(): array
    {
        $rows = [];
        foreach (self::$db->rows('SELECT op_name, credits FROM rep_robj_xtar_use ORDER BY id') as $row) {
            $rows[] = [(string) $row['op_name'], (int) $row['credits']];
        }
        return $rows;
    }
}
