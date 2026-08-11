<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Splits log-viewer's single gate into read and write.
 *
 * opcodesio/log-viewer ships exactly one authorization hook, `viewLogViewer`,
 * and it covers the whole API — including the routes that delete log files,
 * delete whole folders, and drop the index caches. Anyone who can read the
 * logs can therefore erase them, and erasing a log is the one action in that
 * package with no undo and no trace: storage/logs is not in
 * `backup.source.files.include`, so a deleted log is gone for good.
 *
 * That is the same shape as View:Backups vs Restore:Backup and View:Octane vs
 * Reload:Octane, and it gets the same treatment: reading stays on
 * View:LogViewer, mutating needs Delete:LogFile on top.
 *
 * Registered in `config/log-viewer.php` under `api_middleware`, after the
 * package's own AuthorizeLogViewer — the read gate runs first, so an
 * unauthorized visitor never reaches this class at all.
 *
 * Note this cannot hide the buttons: the log-viewer UI is a compiled Vue app
 * that knows nothing about this permission, so a user without Delete:LogFile
 * still sees a Delete button and gets a 403 when they press it. Unpleasant but
 * safe, and the alternative is forking the package's frontend.
 */
class AuthorizeLogViewerWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->mutates($request) && ! $request->user()?->can('Delete:LogFile')) {
            abort(403);
        }

        return $next($request);
    }

    /**
     * Whether this log-viewer API request changes something on disk.
     *
     * Decided on the HTTP method rather than on a list of route names, and the
     * difference only shows up at the next `composer update`. The six routes
     * that mutate something today are:
     *
     *   DELETE folders/{folderIdentifier}              log-viewer.folders.delete
     *   DELETE files/{fileIdentifier}                  log-viewer.files.delete
     *   POST   delete-multiple-files                   log-viewer.files.delete-multiple-files
     *   POST   folders/{folderIdentifier}/clear-cache  log-viewer.folders.clear-cache
     *   POST   files/{fileIdentifier}/clear-cache      log-viewer.files.clear-cache
     *   POST   clear-cache-all                         log-viewer.files.clear-cache-all
     *
     * Everything else the package registers is a GET. Naming those six would
     * be more precise and would fail open: a destructive route added by a
     * later release would not be in the list, would sail straight through, and
     * nothing would turn red. Matching on the method covers it in advance,
     * which is the same bet `MaximumAgeMatchingSchedule` makes with its
     * default-less match — be wrong loudly rather than permissive quietly.
     *
     * The cost is the mirror image: a harmless POST added upstream (saving a
     * UI preference, say) would start needing Delete:LogFile. That surfaces as
     * a 403 someone reports, not as an access grant nobody notices.
     *
     * `isMethodSafe()` is true for GET, HEAD, OPTIONS, and TRACE, so CORS
     * preflight is not caught by this.
     */
    private function mutates(Request $request): bool
    {
        return ! $request->isMethodSafe();
    }
}
