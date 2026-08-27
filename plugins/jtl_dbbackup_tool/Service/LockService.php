<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

/**
 * Spec decision "Nebenläufigkeits-Sperre": exclusive lock while a backup or
 * restore runs, so a scheduled cron backup and a manual restore (or two
 * concurrent admins) can never overlap. Plain file-based flock() rather than
 * a DB row — works without needing the plugin's own tables to exist yet, and
 * survives a killed PHP process cleanly (the OS releases the lock on the
 * handle automatically when the process dies).
 *
 * Fixed a real bug: the previous version tried to auto-clear a "stale" lock
 * by truncating the file's CONTENT — that has no effect whatsoever on an
 * OS-level flock(), which is tied to the open file descriptor, not the
 * file's bytes. If flock() genuinely fails, a process genuinely still holds
 * it (most likely a slow "Komplett" backup on a real, large production
 * database — mysqldump-php is pure PHP and noticeably slower than a native
 * mysqldump binary, and can outlast typical shared-hosting PHP timeouts).
 * Now: no fake auto-clear. Lock age is exposed via isLocked()/lockedSince()
 * so the UI can show it honestly, and forceRelease() (admin-confirmed only)
 * does the actually-correct thing for a truly stuck lock — unlink the file
 * so any still-alive holder is left writing to an orphaned, no-longer-
 * referenced inode, and a fresh acquire() gets a clean new one.
 *
 * Fixed a second real bug (reported: restore ALWAYS failed with "Es läuft
 * bereits ein Backup oder Restore"): RestoreService::restore() acquires this
 * lock, then — when the pre-restore-snapshot option is on (its default is
 * "on") — calls BackupService::createBackup(), which acquires the SAME lock
 * file again through its OWN, separately-constructed LockService instance
 * (BackupServiceFactory::build() always points at the identical
 * `.../.lock` path). flock() is tied to the open file DESCRIPTOR, not the
 * process — a second fopen()+flock() on the same path from the same process
 * does NOT see its own already-held lock and fails immediately (LOCK_NB).
 * So every restore with the default settings deadlocked against itself on
 * the very first line of its own safety-snapshot step.
 * Fix: process-wide reentrant locking, keyed by the lock file path (a plain
 * string key is enough — every call site in this plugin builds this exact
 * path via StorageService::baseDirectory() . '/.lock', so no realpath()
 * normalization is needed). The first acquire() for a given path does the
 * real flock(); any nested acquire() for the SAME path within the same
 * request just bumps a depth counter and returns immediately. release()
 * mirrors this: only the depth-reaching-zero release actually unlocks.
 */
final class LockService
{
    /** @var resource|null */
    private $handle;

    /** @var array<string, int> depth per lock file path, shared across every LockService instance in this request */
    private static array $depth = [];

    /** @var array<string, resource> the real flock()'d handle for each path, owned by whichever instance acquired depth 0→1 */
    private static array $sharedHandles = [];

    private bool $isNestedAcquire = false;

    public function __construct(private readonly string $lockFilePath)
    {
    }

    /**
     * @throws \RuntimeException if a backup or restore is already running
     */
    public function acquire(): void
    {
        if ((self::$depth[$this->lockFilePath] ?? 0) > 0) {
            // Already held by this same PHP process/request (e.g. a restore's
            // pre-restore-snapshot step calling into BackupService::createBackup())
            // — just extend the existing hold, no second flock() call.
            self::$depth[$this->lockFilePath]++;
            $this->isNestedAcquire = true;

            return;
        }

        $handle = \fopen($this->lockFilePath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException(
                \d__('jtl_dbbackup_tool', 'Sperr-Datei konnte nicht geöffnet werden: %s', $this->lockFilePath),
            );
        }

        if (!\flock($handle, \LOCK_EX | \LOCK_NB)) {
            \fclose($handle);

            throw new \RuntimeException(\d__(
                'jtl_dbbackup_tool',
                'Es läuft bereits ein Backup oder Restore. Bitte warten, bis der laufende Vorgang abgeschlossen ist.',
            ));
        }

        \ftruncate($handle, 0);
        \fwrite($handle, (string) \getmypid() . "\n" . \date('c'));
        \fflush($handle);
        \touch($this->lockFilePath);

        $this->handle = $handle;
        self::$sharedHandles[$this->lockFilePath] = $handle;
        self::$depth[$this->lockFilePath] = 1;
        $this->isNestedAcquire = false;
    }

    public function release(): void
    {
        if (!isset(self::$depth[$this->lockFilePath])) {
            return;
        }

        self::$depth[$this->lockFilePath]--;

        if ($this->isNestedAcquire) {
            // This instance never held the real handle — nothing to unlock.
            return;
        }

        if (self::$depth[$this->lockFilePath] <= 0) {
            $handle = self::$sharedHandles[$this->lockFilePath] ?? $this->handle;
            if ($handle !== null) {
                \flock($handle, \LOCK_UN);
                \fclose($handle);
            }
            unset(self::$depth[$this->lockFilePath], self::$sharedHandles[$this->lockFilePath]);
            $this->handle = null;
        }
    }

    /**
     * Best-effort status check: tries a non-blocking shared lock on a SEPARATE
     * handle — if that succeeds, nothing exclusive holds the file right now.
     */
    public function isLocked(): bool
    {
        if (!\file_exists($this->lockFilePath)) {
            return false;
        }

        $probe = \fopen($this->lockFilePath, 'r');
        if ($probe === false) {
            return false;
        }

        $gotLock = \flock($probe, \LOCK_SH | \LOCK_NB);
        if ($gotLock) {
            \flock($probe, \LOCK_UN);
        }
        \fclose($probe);

        return !$gotLock;
    }

    public function lockedSince(): ?\DateTimeImmutable
    {
        if (!\file_exists($this->lockFilePath)) {
            return null;
        }

        $mtime = \filemtime($this->lockFilePath);

        return $mtime !== false ? (new \DateTimeImmutable())->setTimestamp($mtime) : null;
    }

    /**
     * Admin-confirmed escape hatch for a lock that's genuinely stuck (the
     * process that held it is actually dead — e.g. after a server restart or
     * a hard crash) but somehow wasn't released. Deliberately NOT automatic:
     * calling this while the original process is still legitimately running
     * would let a second backup/restore start concurrently with it, which is
     * exactly the corruption risk this lock exists to prevent.
     */
    public function forceRelease(): void
    {
        if (\file_exists($this->lockFilePath)) {
            @\unlink($this->lockFilePath);
        }
    }
}
