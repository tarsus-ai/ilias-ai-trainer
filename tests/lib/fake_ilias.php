<?php

/**
 * Just enough of ILIAS for the plugin's own classes to run unmodified
 * outside an ILIAS installation: the service container, the database
 * interface, users, the repository tree, permissions, the resource
 * storage, mail and the plugin's language files. Everything reads from
 * TarsusIlias\Tests\World, which a test fills as it needs.
 *
 * @license GPL-3.0-or-later
 */

// phpcs:disable

namespace ILIAS\DI {

    use TarsusIlias\Tests\World;

    class Container implements \ArrayAccess
    {
        public function database()
        {
            return World::$db;
        }

        public function user()
        {
            return World::$user;
        }

        public function access()
        {
            return new \ilAccessHandler();
        }

        public function repositoryTree()
        {
            return new \ilTree();
        }

        public function ctrl()
        {
            return World::$ctrl;
        }

        public function tabs()
        {
            return World::$tabs;
        }

        public function language()
        {
            return new \ilLanguage();
        }

        public function logger()
        {
            return new \TarsusIlias\Tests\FakeLoggerFactory();
        }

        public function settings()
        {
            return new \TarsusIlias\Tests\FakeSettings();
        }

        public function resourceStorage()
        {
            return new \TarsusIlias\Tests\FakeStorage();
        }

        public function clientIni()
        {
            return new \TarsusIlias\Tests\FakeIni();
        }

        public function http()
        {
            // No PSR-7 stack here: callers fall back to the superglobals.
            throw new \RuntimeException('no HTTP service in the test world');
        }

        public function offsetExists($offset): bool
        {
            return in_array($offset, ['component.factory', 'tpl', 'mail.mime.sender.factory'], true);
        }

        #[\ReturnTypeWillChange]
        public function offsetGet($offset)
        {
            switch ($offset) {
                case 'component.factory':
                    return new \TarsusIlias\Tests\FakeComponentFactory();
                case 'tpl':
                    return World::$tpl;
                case 'mail.mime.sender.factory':
                    return new \TarsusIlias\Tests\FakeSenderFactory();
            }
            throw new \OutOfBoundsException('no service ' . $offset);
        }

        public function offsetSet($offset, $value): void
        {
        }

        public function offsetUnset($offset): void
        {
        }
    }
}

namespace {

    use TarsusIlias\Tests\World;

    interface ilDBInterface
    {
    }

    interface ilCtrlInterface
    {
    }

    interface ilGlobalTemplateInterface
    {
    }

    abstract class ilPlugin
    {
        abstract public function txt(string $key): string;
    }

    class ilObjUser
    {
        public int $id;
        public string $fullname;
        public string $language;

        public function __construct(int $id = 6, string $fullname = 'Ada Lovelace', string $language = 'en')
        {
            $this->id = $id;
            $this->fullname = $fullname;
            $this->language = $language;
        }

        public function getId(): int
        {
            return $this->id;
        }

        public function getFullname(): string
        {
            return $this->fullname;
        }

        public function getLanguage(): string
        {
            return $this->language;
        }

        public static function _lookupFullname(int $id): string
        {
            return 'User ' . $id;
        }
    }

    class ilLanguage
    {
        public function txt(string $key): string
        {
            return $key;
        }

        public function getLangKey(): string
        {
            return World::$user->language;
        }
    }

    class ilAccessHandler
    {
        public function checkAccess(string $permission, string $cmd, int $refid): bool
        {
            return World::$permissions[$permission . ':' . $refid] ?? true;
        }
    }

    class ilObject
    {
        public const TITLE_LENGTH = 255;

        public static function _exists(int $id, bool $reference = false): bool
        {
            return isset(World::$nodes[$id]);
        }

        public static function _isInTrash(int $refid): bool
        {
            return !empty(World::$nodes[$refid]['trash']);
        }

        public static function _lookupType(int $id, bool $reference = false): string
        {
            return (string) (World::$nodes[$id]['type'] ?? '');
        }

        public static function _lookupObjId(int $refid): int
        {
            return (int) (World::$nodes[$refid]['obj_id'] ?? 0);
        }

        public static function _lookupTitle(int $objid): string
        {
            foreach (World::$nodes as $node) {
                if ($node['obj_id'] === $objid) {
                    return $node['title'];
                }
            }
            return '';
        }

        public static function _lookupDescription(int $objid): string
        {
            foreach (World::$nodes as $node) {
                if ($node['obj_id'] === $objid) {
                    return $node['description'];
                }
            }
            return '';
        }

        public static function _getObjectsByType(string $type): array
        {
            $objects = [];
            foreach (World::$nodes as $node) {
                if ($node['type'] === $type) {
                    $objects[] = ['obj_id' => $node['obj_id'], 'title' => $node['title']];
                }
            }
            return $objects;
        }
    }

    /**
     * The repository tree: every node knows its parent.
     */
    class ilTree
    {
        public function isInTree(int $refid): bool
        {
            return isset(World::$nodes[$refid]);
        }

        public function getParentId(int $refid): int
        {
            return (int) (World::$nodes[$refid]['parent'] ?? 0);
        }

        public function isGrandChild(int $ancestor, int $refid): bool
        {
            $current = $this->getParentId($refid);
            for ($depth = 0; $current > 0 && $depth < 50; $depth++) {
                if ($current === $ancestor) {
                    return true;
                }
                $current = $this->getParentId($current);
            }
            return false;
        }

        /**
         * The nearest node of a type on the path to the root, the node
         * itself included.
         */
        public function checkForParentType(int $refid, string $type): int
        {
            $current = $refid;
            for ($depth = 0; $current > 0 && $depth < 50; $depth++) {
                if ((World::$nodes[$current]['type'] ?? '') === $type) {
                    return $current;
                }
                $current = $this->getParentId($current);
            }
            return 0;
        }

        public function getNodeData(int $refid): array
        {
            $node = World::$nodes[$refid] ?? null;
            return $node === null ? [] : $node + ['child' => $refid];
        }

        public function getSubTree(array $root, bool $withdata = true, array $types = []): array
        {
            $rootref = (int) ($root['child'] ?? ($root['ref_id'] ?? 0));
            $nodes = [];
            foreach (World::$nodes as $refid => $node) {
                if ($refid !== $rootref && !$this->isGrandChild($rootref, $refid)) {
                    continue;
                }
                if (!empty($node['trash']) || ($types !== [] && !in_array($node['type'], $types, true))) {
                    continue;
                }
                $nodes[] = $node + ['child' => $refid];
            }
            return $nodes;
        }
    }

    /**
     * Mails are collected instead of sent.
     */
    class ilMimeMail
    {
        private array $mail = ['to' => [], 'subject' => '', 'body' => ''];

        public function From($sender): void
        {
        }

        public function To($recipients): void
        {
            $this->mail['to'] = (array) $recipients;
        }

        public function Subject(string $subject): void
        {
            $this->mail['subject'] = $subject;
        }

        public function Body(string $body): void
        {
            $this->mail['body'] = $body;
        }

        public function Send(): void
        {
            World::$mails[] = $this->mail;
        }
    }
}

namespace TarsusIlias\Tests {

    final class FakePlugin extends \ilPlugin
    {
        /** @var array<string, array<string, string>> */
        private static array $languages = [];

        public function txt(string $key): string
        {
            $strings = self::strings(World::$user->language === 'de' ? 'de' : 'en');
            // ILIAS wraps a missing variable in dashes.
            return $strings[$key] ?? '-' . $key . '-';
        }

        public function getDirectory(): string
        {
            return './Customizing/global/plugins/Services/Repository/RepositoryObject/Tarsus';
        }

        /**
         * @return array<string, string>
         */
        public static function strings(string $language): array
        {
            if (!isset(self::$languages[$language])) {
                self::$languages[$language] = self::parse(PLUGIN_ROOT . '/lang/ilias_' . $language . '.lang');
            }
            return self::$languages[$language];
        }

        /**
         * @return array<string, string>
         */
        public static function parse(string $file): array
        {
            $strings = [];
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if ($line === '' || strncmp($line, '//', 2) === 0) {
                    continue;
                }
                $parts = explode('#:#', $line, 2);
                if (count($parts) === 2) {
                    $strings[$parts[0]] = $parts[1];
                }
            }
            return $strings;
        }
    }

    final class FakeComponentFactory
    {
        public function getPlugin(string $id): \ilPlugin
        {
            return new FakePlugin();
        }
    }

    final class FakeLoggerFactory
    {
        public function root(): FakeLogger
        {
            return new FakeLogger();
        }
    }

    final class FakeLogger
    {
        public function error(string $message): void
        {
            World::$log[] = 'error: ' . $message;
        }

        public function warning(string $message): void
        {
            World::$log[] = 'warning: ' . $message;
        }

        public function info(string $message): void
        {
            World::$log[] = 'info: ' . $message;
        }
    }

    final class FakeSettings
    {
        public function get(string $key, ?string $default = null): ?string
        {
            return World::$settings[$key] ?? $default;
        }
    }

    final class FakeIni
    {
        public function readVariable(string $group, string $name): string
        {
            return 'Test Academy';
        }
    }

    final class FakeSenderFactory
    {
        public function system(): object
        {
            return new \stdClass();
        }
    }

    /**
     * The resource storage: files a test registered under a resource id.
     */
    final class FakeStorage
    {
        public function manage(): self
        {
            return $this;
        }

        public function consume(): self
        {
            return $this;
        }

        public function find(string $rid): ?string
        {
            return isset(World::$files[$rid]) ? $rid : null;
        }

        public function getCurrentRevision(string $rid): FakeRevision
        {
            return new FakeRevision(World::$files[$rid]);
        }

        public function stream(string $rid): self
        {
            $this->rid = $rid;
            return $this;
        }

        public function getStream(): FakeStream
        {
            return new FakeStream((string) World::$files[$this->rid]['path']);
        }

        private string $rid = '';
    }

    final class FakeRevision
    {
        /** @var array<string, mixed> */
        private array $file;

        public function __construct(array $file)
        {
            $this->file = $file;
        }

        public function getInformation(): self
        {
            return $this;
        }

        public function getVersionNumber(): int
        {
            return (int) ($this->file['version'] ?? 1);
        }

        public function getSize(): int
        {
            return (int) filesize((string) $this->file['path']);
        }

        public function getMimeType(): string
        {
            return (string) $this->file['mimetype'];
        }

        public function getTitle(): string
        {
            return (string) ($this->file['title'] ?? '');
        }
    }

    final class FakeStream
    {
        /** @var resource */
        private $handle;

        public function __construct(string $path)
        {
            $this->handle = fopen($path, 'rb');
        }

        public function rewind(): void
        {
            rewind($this->handle);
        }

        public function eof(): bool
        {
            return feof($this->handle);
        }

        public function read(int $length): string
        {
            return (string) fread($this->handle, $length);
        }

        public function close(): void
        {
            fclose($this->handle);
        }
    }
}
