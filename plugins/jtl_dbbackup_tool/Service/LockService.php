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
 */
final class LockService
{
    /** @var resource|null */
    private $handle;

    public function __construct(private readonly string $lockFilePath)
    {
    }

    /**
     * @throws \RuntimeException if a backup or restore is already running
     */
    public function acquire(): void
    {
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
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            \flock($this->handle, \LOCK_UN);
            \fclose($this->handle);
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
