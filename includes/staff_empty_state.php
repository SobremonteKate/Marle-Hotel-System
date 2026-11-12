<?php
/**
 * staff_empty_state.php — one empty panel, one next action.
 *
 * A blank table says "the screen is broken" more loudly than it says "nothing
 * is waiting". Every empty list in the console should use this instead: a quiet
 * icon, a sentence that says what the absence means, and — where there is one —
 * the action that fills it.
 *
 * HOW IT IS USED
 *   <?php
 *   $emptyIcon  = 'bi-check2-circle';
 *   $emptyTitle = 'Nothing waiting';
 *   $emptyBody  = 'New guest requests land here the moment they are sent.';
 *   require __DIR__ . '/../includes/staff_empty_state.php';
 *   ?>
 *
 *   Optional: $emptyActionHtml (pre-rendered link/button markup).
 *
 * Both sentences are wrapped in ui_t() so the Tagalog switch covers them; the
 * caller passes the English and the Filipino side together.
 */

$emptyIcon  = $emptyIcon ?? 'bi-inbox';
$emptyTitle = $emptyTitle ?? 'Nothing here yet';
$emptyTitleTl = $emptyTitleTl ?? 'Wala pa rito';
$emptyBody  = $emptyBody ?? '';
$emptyBodyTl = $emptyBodyTl ?? '';
$emptyActionHtml = $emptyActionHtml ?? '';
?>
<div class="staff-empty" role="status">
    <span class="staff-empty__icon" aria-hidden="true"><i class="bi <?= htmlspecialchars($emptyIcon, ENT_QUOTES, 'UTF-8') ?>"></i></span>
    <p class="staff-empty__title"><?= htmlspecialchars(function_exists('ui_t') ? ui_t($emptyTitle, $emptyTitleTl) : $emptyTitle, ENT_QUOTES, 'UTF-8') ?></p>
<?php if ($emptyBody !== ''): ?>
    <p class="staff-empty__body"><?= htmlspecialchars(function_exists('ui_t') ? ui_t($emptyBody, $emptyBodyTl !== '' ? $emptyBodyTl : $emptyBody) : $emptyBody, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($emptyActionHtml !== ''): ?>
    <div class="staff-empty__action"><?= $emptyActionHtml ?></div>
<?php endif; ?>
</div>
