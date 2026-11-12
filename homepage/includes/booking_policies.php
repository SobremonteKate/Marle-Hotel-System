<?php
/**
 * dining_policies.php — what a guest agrees to, said once, in plain words.
 *
 * The modal's checkbox carried all of this in a single 200-word sentence, and
 * the payment details only ever arrived by email. A guest deciding whether to
 * book should be able to read it before they fill anything in.
 *
 * Every figure here comes from the same source the modal, the emails and the
 * booking status page use (includes/booking_deposit.php and the Settings
 * entries behind it), so changing the deposit rate cannot leave this block
 * quoting the old one.
 *
 * Requires: $conn (mysqli), site_t(), includes/booking_deposit.php.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

$policyDeposit = (int)marle_deposit_rate($conn);
$policyForfeit = (int)MARLE_CANCELLATION_FORFEIT_RATE;
$policyPayment = marle_deposit_method_text($conn);
?>

<section class="section policies" id="policies" aria-labelledby="policiesTitle">
    <div class="shell">
        <div class="section-head" data-reveal>
            <div>
                <p class="eyebrow"><?= site_t('Before you book', 'Bago ka mag-book') ?></p>
                <h2 class="section-title" id="policiesTitle"><?= site_t('Deposit, payment and cancellation', 'Deposito, bayad at pagkansela') ?></h2>
            </div>
            <p class="section-lead">
                <?= site_t(
                    'Four things worth knowing before you send the form. Nothing here is a surprise later.',
                    'Apat na bagay na dapat mong malaman bago isumite ang form. Wala nang sorpresa sa huli.'
                ) ?>
            </p>
        </div>

        <ol class="policies__grid" data-reveal>
            <li class="policy">
                <span class="policy__num" aria-hidden="true">01</span>
                <h3 class="policy__title"><?= site_t('A deposit confirms the room', 'Ang deposito ang nagkukumpirma ng kwarto') ?></h3>
                <p class="policy__body">
                    <?= site_t(
                        'Submitting the form only requests the room. It is held once a reservation deposit of',
                        'Ang pagsusumite ng form ay kahilingan pa lang. Kukumpirmahin ang kwarto kapag may reserbasyong deposito na'
                    ) ?>
                    <strong><?= $policyDeposit ?>%</strong>
                    <?= site_t(
                        'of the booking total is received and verified. The balance is paid at the front desk on arrival.',
                        'ng kabuuang halaga ng booking na natanggap at na-verify. Ang balanse ay binabayaran sa front desk pagdating.'
                    ) ?>
                </p>
            </li>

            <li class="policy">
                <span class="policy__num" aria-hidden="true">02</span>
                <h3 class="policy__title"><?= site_t('How to pay it', 'Paano ito bayaran') ?></h3>
                <p class="policy__body">
                    <?= site_t('By GCash or bank transfer', 'Sa GCash o bank transfer') ?> &mdash;
                    <span class="policy__payment"><?= htmlspecialchars($policyPayment, ENT_QUOTES, 'UTF-8') ?></span>.
                    <?= site_t(
                        'Upload the proof of payment on your booking status page; the front desk verifies it and confirms your room.',
                        'I-upload ang patunay ng bayad sa iyong booking status page; i-verify ito ng front desk at ikukumpirma ang kwarto.'
                    ) ?>
                </p>
            </li>

            <li class="policy">
                <span class="policy__num" aria-hidden="true">03</span>
                <h3 class="policy__title"><?= site_t('If you cancel', 'Kung magkansela ka') ?></h3>
                <p class="policy__body">
                    <strong><?= $policyForfeit ?>%</strong>
                    <?= site_t(
                        'of the total booking amount is non-refundable. You can cancel from the booking status page, or by calling the front desk.',
                        'ng kabuuang halaga ng booking ang hindi na maibabalik. Pwede kang magkansela sa booking status page, o tumawag sa front desk.'
                    ) ?>
                </p>
            </li>

            <li class="policy">
                <span class="policy__num" aria-hidden="true">04</span>
                <h3 class="policy__title"><?= site_t('If plans change on the day', 'Kung magbago ang plano sa araw mismo') ?></h3>
                <p class="policy__body">
                    <?= site_t(
                        'Check-in starts at 3:00 PM, and the front desk is staffed 24 hours, so a late arrival is no problem — call and we will hold the room. A booking that has neither checked in nor called by 4:00 PM on the arrival day is released back to the house as a no-show, and the cancellation rule above applies.',
                        'Simula 3:00 PM ang check-in, at 24 oras na may tao sa front desk, kaya hindi problema ang mahuling pagdating — tumawag lang at itatabi namin ang kwarto. Ang booking na hindi pa nag-check in at hindi tumawag bago mag-4:00 PM sa araw ng pagdating ay ibinabalik sa available na kwarto bilang no-show, at ang patakaran sa pagkansela sa itaas ang susundin.'
                    ) ?>
                </p>
            </li>
        </ol>

        <p class="policies__foot">
            <a class="text-link" href="booking_status.php"><?= site_t('Check your booking status', 'Tingnan ang status ng booking') ?>
                <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            <a class="text-link" href="tel:09170000000">
                <i class="bi bi-telephone" aria-hidden="true"></i><?= site_t('Call the front desk', 'Tumawag sa front desk') ?></a>
        </p>
    </div>
</section>
