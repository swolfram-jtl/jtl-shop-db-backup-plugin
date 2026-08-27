{* Shared flash-message banner — included at the very top of every tab's
   .dbbackup-page, right after the opening div. $flashMessage/$flashSuccess
   are assigned by every Controller\*Controller::render(), merging in
   Service\FlashBus's cross-tab result when the controller's own local
   handling didn't already set one — see FlashBus's docblock for the bug
   this fixes (a backup-trigger POST's result showing up on the wrong tab).
   Rendering the identical banner in every tab means it always appears on
   whichever tab actually ends up active, regardless of which controller's
   file happened to run the action. Sticky-positioned so it stays visible
   near the top of the tab's own content as a "global, above the content"
   banner — Shop core's own nav-tabs markup (admin/templates/bootstrap/
   tpl_inc/plugin_uebersicht.tpl) isn't reachable from a plugin template, so
   a literal position above the tab bar itself isn't possible from here. *}
{if $flashMessage}
<div class="alert alert-dismissible dbbackup-flash-banner {if $flashSuccess}alert-success{else}alert-danger{/if} shadow-sm" role="alert">
    {$flashMessage|escape}
    <button type="button" class="close" data-dismiss="alert" aria-label="Schließen"><span aria-hidden="true">&times;</span></button>
</div>
{/if}
