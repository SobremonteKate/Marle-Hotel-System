<?php
/**
 * booking_details_modal.php — one read-only "Booking details" viewer shared by
 * the admin / employee / owner Requests → Bookings tabs.
 *
 * A booking whose guest has already been checked in must not be amended or
 * cancelled from the queue (the stay is underway), so the card drops those two
 * buttons and offers this instead. Everything the modal shows is already on the
 * card, so the button carries the values as data-* attributes and no extra
 * request is made — the modal is purely a larger, clearer read.
 *
 * Usage (inside the booking card, when marle_bookings_checked_in() says so):
 *
 *   <button type="button" class="btn btn-outline-secondary btn-sm"
 *           onclick="openBookingDetails(this)"
 *           data-ref="#12" data-guest="Jane" ...>View booking details</button>
 *
 * and once per page, just before </body>:
 *
 *   <?php marle_booking_details_assets(); ?>
 */

if (!function_exists('marle_booking_details_assets')) {
    function marle_booking_details_assets(): void
    {
        ?>
    <!-- ── BOOKING DETAILS (read-only) ── -->
    <div class="modal fade" id="bookingDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-journal-text me-2"></i>Booking details
                        <span class="text-muted small ms-2" id="bookingDetailsRef"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-success py-2 small d-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-check-circle-fill"></i>
                        <span>This stay is already under way, so the booking can no longer be amended or
                            cancelled from here. Manage the room from the Front Desk / Room Board instead.</span>
                    </div>
                    <dl class="row mb-0 small" id="bookingDetailsList"></dl>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var instance = null;

        function getModal() {
            if (!instance) {
                instance = new bootstrap.Modal(document.getElementById('bookingDetailsModal'));
            }
            return instance;
        }

        /* Each field is [label, data-attribute, key]. A field with an empty value
           simply does not render, so a booking with no phone or no special
           request reads cleanly instead of showing blank rows. */
        var FIELDS = [
            ['Guest',           'guest'],
            ['Room',            'room'],
            ['Room type',       'roomType'],
            ['Stay',            'stay'],
            ['Guests',          'persons'],
            ['Email',           'email'],
            ['Phone',           'phone'],
            ['Status',          'status'],
            ['Reservation deposit', 'deposit'],
            ['Special requests', 'requests']
        ];

        window.openBookingDetails = function (btn) {
            var list = document.getElementById('bookingDetailsList');
            var ref  = document.getElementById('bookingDetailsRef');
            list.innerHTML = '';
            ref.textContent = btn.dataset.ref || '';

            FIELDS.forEach(function (field) {
                var label = field[0];
                var value = btn.dataset[field[1]];
                if (!value) return;

                var dt = document.createElement('dt');
                dt.className = 'col-5 fw-semibold text-muted';
                dt.textContent = label;

                var dd = document.createElement('dd');
                dd.className = 'col-7';
                dd.textContent = value;

                list.appendChild(dt);
                list.appendChild(dd);
            });

            getModal().show();
        };
    })();
    </script>
        <?php
    }
}
