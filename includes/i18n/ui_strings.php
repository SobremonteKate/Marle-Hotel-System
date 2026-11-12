<?php
/**
 * ui_strings.php — English → Tagalog for the staff dashboards and guest portal.
 *
 * Read by includes/ui_i18n.php; see that file for how a page uses the switch.
 *
 * SHAPE
 *   'common'    phrases that appear on more than one screen (the sidebar, the
 *               breadcrumb, the buttons everybody shares). One line here fixes
 *               every screen that shows it — the sidebar is duplicated in all
 *               thirty staff files, so this is where most of the saving is.
 *   'fragments' phrases translated *inside* a longer line, for copy wrapped
 *               around a value the table cannot know. A whole clause only, never
 *               a bare word: a fragment is matched wherever it appears, so a
 *               one-word fragment could land in the middle of a guest's name.
 *   'screens'   per screen id, from ui_screens(); a screen's own line wins over
 *               the same English phrase in 'common'.
 *
 * RULES
 *   - The key is the exact rendered text as ui_flat() sees it: entities decoded,
 *     non-breaking spaces folded to plain ones, whitespace collapsed. It is
 *     matched against text nodes and against the prose attributes placeholder /
 *     title / aria-label / alt / data-tooltip.
 *   - English that Filipino software keeps as-is is left out entirely — no entry
 *     equals its own translation (i18n_scan.php rejects one), and no entry is
 *     "translated" into itself just to look complete. That is why Dashboard,
 *     Menu, Token, Username and the restaurant's dish names have no line here.
 *   - Machine values are deliberately absent: activity-log action codes and
 *     option values are identifiers a handler is waiting for, not prose.
 *   - Values are HTML-escaped on the way out, so an apostrophe or an ampersand is
 *     safe here.
 */

return [
    'common' => [
        // ── Sidebar / breadcrumb: the same markup in every staff file ───────
        "Requests"                  => "Serbisyo",
        "Guest Tokens"              => "Token ng Bisita",
        "Transactions"              => "Transaksyon",
        "Inventory"                 => "Imbentaryo",
        "Guest Record"              => "Talaan ng Bisita",
        "Calendar"                  => "Kalendaryo",
        "Reviews"                   => "Pagsusuri at Rekomendasyon",
        "Settings"                  => "Pagsasaayos",
        "System Log"                => "Aktibidad ng Sistema",
        "Logout"                    => "Mag-log out",
        "/ Requests"                => "/ Serbisyo",
        "/ Transactions"            => "/ Transaksyon",
        "/ Guest Tokens"            => "/ Token ng Bisita",
        "/ Inventory"               => "/ Imbentaryo",
        "/ Guest Record"            => "/ Talaan ng Bisita",
        "/ Calendar"                => "/ Kalendaryo",
        "/ Reviews"                 => "/ Pagsusuri at Rekomendasyon",
        "/ Settings"                => "/ Pagsasaayos",
        "/ System Log"              => "/ Aktibidad ng Sistema",
        "› Transactions"           => "› Transaksyon",
        "› Guest Tokens"           => "› Token ng Bisita",
        "› Guest Record"           => "› Talaan ng Bisita",
        "› Calendar"               => "› Kalendaryo",
        "› Reviews"                => "› Pagsusuri at Rekomendasyon",
        "› Settings"               => "› Pagsasaayos",
        "› Inventory"              => "› Imbentaryo",
        // ── Console shell, 2026-10-19: the key rack's group labels and the
        //    page names the shared desk strip introduced. ────────────────
        "Rooms & guests"            => "Silid at bisita",
        "Money & stock"             => "Bayad at imbentaryo",
        "Management"                => "Pamamahala",
        "Owner"                     => "May-ari",
        "Guest Archive"             => "Archive ng Bisita",
        "Inventory Archive"         => "Archive ng Imbentaryo",
        "User Manual"               => "Manwal ng Gumagamit",
        "Email check"               => "Pagsusuri ng Email",
        "Transaction History"       => "Kasaysayan ng Transaksyon",
        "Open navigation menu"      => "Buksan ang menu ng nabigasyon",
        "Close"                     => "Isara",
        "Notifications"             => "Mga Abiso",
        "New"                       => "Bago",
        "Unread"                    => "Hindi Nabasa",
        "Unread Messages"           => "Mga Hindi Nabasang Mensahe",
        "New Bookings"              => "Mga Bagong Booking",
        "Extension Requests"        => "Mga Kahilingan sa Pagpapahaba",
        "Service Requests"          => "Mga Kahilingan sa Serbisyo",
        "Food Orders"               => "Mga Order ng Pagkain",
        "Pending Requests"          => "Mga Nakabinbing Kahilingan",
        "Expiring"                  => "Mag-e-expire",
        "Expiring Soon"             => "Malapit Nang Mag-expire",
        "All caught up!"            => "Wala nang bago!",
        "See All Requests & Messages" => "Tingnan Lahat ng Kahilingan at Mensahe",
        "Mark all read"             => "Markahan lahat bilang nabasa",
        "Confirm Logout"            => "Kumpirmahin ang Pag-log out",
        "Confirm Deletion"          => "Kumpirmahin ang Pagbura",
        "Confirm Archive"           => "Kumpirmahin ang Pag-archive",
        "Are you sure you want to log out?" => "Sigurado ka bang gusto mong mag-log out?",

        // ── Buttons, filters and the words every table header uses ──────────
        "Yes"                       => "Oo",
        "No"                        => "Hindi",
        "Cancel"                    => "Kanselahin",
        "Save"                      => "I-save",
        "Save Changes"              => "I-save ang mga Pagbabago",
        "Update"                    => "I-update",
        "Edit"                      => "I-edit",
        "Delete"                    => "Burahin",
        "Archive"                   => "I-archive",
        "Search"                    => "Maghanap",
        "Search..."                 => "Maghanap...",
        "Apply"                     => "Ilapat",
        "All"                       => "Lahat",
        "Next"                      => "Susunod",
        "Previous"                  => "Nauna",
        "Back"                      => "Bumalik",
        "Reset"                     => "I-reset",
        "Clear Filter"              => "Alisin ang Filter",
        "All Time"                  => "Lahat ng Panahon",
        "Daily"                     => "Arawan",
        "Weekly"                    => "Lingguhan",
        "Monthly"                   => "Buwanan",
        "This Week"                 => "Ngayong Linggo",
        "This Month"                => "Ngayong Buwan",
        "Last Month"                => "Nakaraang Buwan",
        "This Year"                 => "Ngayong Taon",
        "Collect Payment"           => "Kolektahin ang Bayad",
        "Yes, Logout"               => "Oo, Mag-log out",
        "Terms of use"              => "Mga Tuntunin sa Paggamit",
        "Go to inventory"           => "Pumunta sa imbentaryo",
        "Open the request queue"    => "Buksan ang listahan ng kahilingan",
        "Review transactions"       => "Tingnan ang mga transaksyon",
        "Review now"                => "Mag-review na",
        "⭐ 1 Star"                  => "⭐ 1 Bituin",
        "⭐⭐ 2 Stars"               => "⭐⭐ 2 Bituin",
        "⭐⭐⭐ 3 Stars"             => "⭐⭐⭐ 3 Bituin",
        "⭐⭐⭐⭐ 4 Stars"           => "⭐⭐⭐⭐ 4 Bituin",
        "⭐⭐⭐⭐⭐ 5 Stars"         => "⭐⭐⭐⭐⭐ 5 Bituin",
        "Actions"                   => "Mga Aksyon",
        // Actions and links the staff pages repeat all over their tables.
        "Approve"                   => "Aprubahan",
        "Approve Booking"           => "Aprubahan ang Booking",
        "Deny"                      => "Tanggihan",
        "Deny Booking"              => "Tanggihan ang Booking",
        "Deny Extension Request"    => "Tanggihan ang Kahilingan sa Pagpapahaba",
        "Deny Food Order"           => "Tanggihan ang Order ng Pagkain",
        "Deny Service Request"      => "Tanggihan ang Kahilingan sa Serbisyo",
        "Confirm Denial"            => "Kumpirmahin ang Pagtanggi",
        "Confirm Rejection"         => "Kumpirmahin ang Pagtanggi",
        "Cancel booking"            => "Kanselahin ang booking",
        "Cancel this booking"       => "Kanselahin ang booking na ito",
        "Amend Booking"             => "I-amend ang Booking",
        "Amend booking"             => "I-amend ang booking",
        "View booking details"      => "Tingnan ang detalye ng booking",
        "View proof"                => "Tingnan ang patunay",
        "View Archive"              => "Tingnan ang Archive",
        "Back to list"              => "Bumalik sa listahan",
        "Archive Selected"          => "I-archive ang mga Pinili",
        "Add Item"                  => "Magdagdag ng Item",
        "Add to Order"              => "Idagdag sa Order",
        "Add new event"             => "Magdagdag ng bagong event",
        "Copy"                      => "Kopyahin",
        "Print"                     => "I-print",
        "Refresh"                   => "I-refresh",
        "Revoke"                    => "Bawiin",
        "Filter"                    => "Salain",
        "Apply filters"             => "Ilapat ang mga filter",
        "Save changes"              => "I-save ang mga pagbabago",
        "Export CSV"                => "I-export ang CSV",
        "Download PDF"              => "I-download ang PDF",
        "Prev"                      => "Nauna",
        "Summary"                   => "Buod",
        "Detailed"                  => "Detalyado",
        "Strengths"                 => "Mga Kalakasan",
        "Concerns"                  => "Mga Alalahanin",
        "Bookings"                  => "Mga Booking",
        "Booking Details"           => "Detalye ng Booking",
        "Booking details"           => "Mga detalye ng booking",
        "Booking approvals"         => "Mga aprubasyon ng booking",
        "Event Details"             => "Detalye ng Event",
        "Guest requests"            => "Mga kahilingan ng bisita",
        "Old Conversations"         => "Mga Lumang Usapan",
        "Unpaid Collections"        => "Mga Hindi Bayad na Koleksyon",
        "Unpaid balances"           => "Mga hindi bayad na balanse",
        "Low Stock Only"            => "Mababang Stock Lamang",
        "Low stock items"           => "Mga kulang sa stock",
        "At or below threshold"     => "Nasa o mas mababa sa hangganan",
        "Waiting for a decision"    => "Naghihintay ng desisyon",
        "Action Plan"               => "Plano ng Aksyon",
        "Category Breakdown"        => "Hati-hati ayon sa Kategorya",
        "All Rating"                => "Lahat ng Rating",
        "Analyze Selected Reviews"  => "Suriin ang mga Piniling Puna",
        "Per-Review Sentiment"      => "Sentiment ng Bawat Puna",
        "Sentiment Analysis"        => "Pagsusuri ng Sentiment",
        "Generate & Issue Token"    => "Gumawa at Maglabas ng Token",
        "Backup Database"           => "I-backup ang Database",
        "User Manual"               => "Manwal ng User",
        // The shared footer (includes/staff_footer.php), one per role.
        "Front Desk console"        => "Console ng Front Desk",
        "Manager console"           => "Console ng Manager",
        "Owner console"             => "Console ng May-ari",
        "Create a current backup to reduce risk from accidental data loss." =>
            "Gumawa ng bagong backup upang mabawasan ang panganib ng hindi sinasadyang pagkawala ng datos.",
        "Open the guide for workflows, references, and admin help documentation." =>
            "Buksan ang gabay para sa mga workflow, sanggunian, at dokumentasyon ng admin.",
        "Skim the day-to-day quick reference. The complete guide is in the sidebar under" =>
            "Tingnan ang mabilisang sanggunian. Nasa sidebar ang kumpletong gabay sa ilalim ng",
        "delivered to you"          => "inihahatid sa iyo",
        "at"                        => "sa",
        "(optional)"                => "(opsyonal)",
        "Quantity"                  => "Dami",
        "Extension"                 => "Pagpapahaba",
        "Title"                     => "Pamagat",
        "Done"                      => "Tapos",
        "Yes, Archive"              => "Oo, I-archive",
        "Amenities"                 => "Mga Pasilidad",
        "Set Room Override"         => "Itakda ang Override ng Silid",
        "Maintenance Note"          => "Tala sa Pag-aayos",
        "Mark as Under Maintenance" => "Markahan bilang Nasa Pag-aayos",
        "Cancel Booking"            => "Kanselahin ang Booking",
        "e.g. Plumbing repair, estimated 2 days" => "hal. Pag-aayos ng tubo, tinatayang 2 araw",
        "🔍 Search inventory…"      => "🔍 Hanapin sa imbentaryo…",
        "Deluxe Bath & Spa"         => "Deluxe Bath at Spa",
        "Room Cleaning"             => "Paglilinis ng Silid",
        "Room Service"              => "Serbisyo sa Silid",
        "Request Deluxe Bath and Spa" => "I-request ang Deluxe Bath and Spa",
        "Request Room Cleaning"     => "I-request ang Paglilinis ng Silid",
        "Request Room Service"      => "I-request ang Serbisyo sa Silid",
        "Action"                    => "Aksyon",
        "Status"                    => "Katayuan",
        "Details"                   => "Mga Detalye",
        "Description"               => "Paglalarawan",
        "Category"                  => "Kategorya",
        "Date"                      => "Petsa",
        "Date / Time"               => "Petsa / Oras",
        "Time"                      => "Oras",
        "Today"                     => "Ngayon",
        "From"                      => "Mula",
        "To"                        => "Hanggang",
        "Type"                      => "Uri",
        "Room"                      => "Silid",
        "Room No."                  => "Bilang ng Silid",
        "Room Type"                 => "Uri ng Silid",
        "Room number"               => "Bilang ng silid",
        "Guest"                     => "Bisita",
        "Guests"                    => "Mga Bisita",
        "Guest / Room"              => "Bisita / Silid",
        "Persons"                   => "Bilang ng Tao",
        "Hours"                     => "Oras",
        "Amount"                    => "Halaga",
        "Total"                     => "Kabuuan",
        "Note"                      => "Tala",
        "Notes"                     => "Mga Tala",
        "Role"                      => "Tungkulin",
        "Optional"                  => "Opsyonal",
        "Required"                  => "Kailangan",

        // ── Statuses: the same vocabulary on the boards, the tables and the
        //    guest portal's filters ──────────────────────────────────────────
        "Extensions"                => "Mga Extension",
        "Food"                      => "Pagkain",
        "Service"                   => "Serbisyo",
        "Check-in"                  => "Pag-check-in",
        "Check-out"                 => "Pag-check-out",
        "Pending"                   => "Nakabinbin",
        "Approved"                  => "Aprubado",
        "Denied"                    => "Tinanggihan",
        "Cancelled"                 => "Kinansela",
        "Paid"                      => "Bayad",
        "Unpaid"                    => "Hindi Bayad",
        "Available"                 => "Bakante",
        "Occupied"                  => "Okupado",
        "Booked"                    => "Nakareserba",
        "Maintenance"               => "Nasa Pag-aayos",
        "Checked In"                => "Nakapasok Na",
        "Checked Out"               => "Nakaalis Na",
        "Success"                   => "Tagumpay",
        "Failure"                   => "Kabiguan",

        // ── The privacy / terms modal, on every staff page and the portal ───
        "Data Privacy"              => "Pribasiya ng Datos",
        "Terms & Conditions"        => "Mga Tuntunin at Kundisyon",
        "1. Acceptance of Terms"    => "1. Pagtanggap sa mga Tuntunin",
        "2. User Responsibilities"  => "2. Mga Pananagutan ng Gumagamit",
        "3. Data Privacy and Protection" => "3. Pribasiya at Proteksyon ng Datos",
        "4. System Security"        => "4. Seguridad ng Sistema",
        "5. Limitation of Liability" => "5. Hangganan ng Pananagutan",
        "6. Amendments"             => "6. Mga Pagbabago",
        "1. Information We Collect" => "1. Impormasyong Kinokolekta Namin",
        "2. How We Use Your Information" => "2. Paano Namin Ginagamit ang Iyong Impormasyon",
        "3. Data Retention"         => "3. Pagtatago ng Datos",
        "By accessing and using this system, you acknowledge that you have read, understood, and agreed to comply with all applicable policies, rules, and regulations governing system usage." =>
            "Sa paggamit ng sistemang ito, kinikilala mong nabasa, naunawaan, at sinang-ayunan ang lahat ng patakaran at alituntuning gumagabay sa paggamit nito.",
        "Users are responsible for maintaining the confidentiality of their access token and for all activities conducted under their session." =>
            "Pananagutan ng gumagamit ang pagiging kompidensyal ng kanyang access token at ang lahat ng gawaing isinasagawa sa ilalim ng kanyang sesyon.",
        "All personal data collected will be handled in accordance with data protection laws and company privacy policies." =>
            "Ang lahat ng personal na datos na makokolekta ay hahawakan ayon sa mga batas sa proteksyon ng datos at mga patakaran ng kumpanya sa pribasiya.",
        "Unauthorized access, misuse, or tampering with system data is strictly prohibited." =>
            "Mahigpit na ipinagbabawal ang walang pahintulot na pagpasok, maling paggamit, o pagbabago ng datos ng sistema.",
        "The company shall not be liable for any damages resulting from improper system use or unauthorized access." =>
            "Hindi mananagot ang kumpanya sa anumang pinsalang dulot ng maling paggamit ng sistema o walang pahintulot na pagpasok.",
        "These terms may be updated at any time without prior notice. Continued use constitutes acceptance." =>
            "Maaaring baguhin ang mga tuntuning ito anumang oras nang walang paunang abiso. Ang patuloy na paggamit ay pagtanggap.",
        "We collect information necessary to manage your stay, including your room assignment and stay duration." =>
            "Kinokolekta namin ang impormasyong kailangan upang pamahalaan ang iyong paninirahan, kabilang ang iyong silid at tagal ng paninirahan.",
        "Your information is used solely to provide hotel services and manage your guest portal access during your stay." =>
            "Ang iyong impormasyon ay ginagamit lamang upang magbigay ng mga serbisyo ng hotel at pamahalaan ang iyong access sa guest portal habang naninirahan ka.",
        "We retain information only as long as needed to provide services and meet legal obligations." =>
            "Itinatago namin ang impormasyon hanggang sa kailangan lamang upang magbigay ng serbisyo at tuparin ang mga legal na tungkulin.",
        "Do not share your access token." => "Huwag ibahagi ang iyong access token.",
        "Log out after each session." => "Mag-log out pagkatapos ng bawat sesyon.",
        "Report suspicious activity immediately." => "Iulat agad ang kahina-hinalang gawain.",

        // ── The chat widget, on every staff page and in the portal ──────────
        "Attach file (max 5 MB)"    => "Mag-attach ng file (hanggang 5 MB)",
        "Image preview"             => "Preview ng larawan",
        "Type a message..."         => "Mag-type ng mensahe...",
        "Send message"              => "Ipadala ang mensahe",
        "Remove file"               => "Alisin ang file",

        // ── Portal chrome (shared header, bottom bar and footer) ─────────────
        "Home"                      => "Tahanan",
        "Services"                  => "Serbisyo",
        "Extend"                    => "Magdagdag",
        "Guide"                     => "Gabay",
        "Sign Out"                  => "Mag-sign out",
        "Session expires in"        => "Mae-expire ang sesyon sa",
        "Session Expired"           => "Nag-expire ang Sesyon",
        "Your portal access has ended. Please visit the front desk if you need a new token." =>
            "Tapos na ang iyong access sa portal. Pumunta sa front desk kung kailangan mo ng bagong token.",
        "Back to Login"             => "Bumalik sa Login",
        "Session Expiring Soon"     => "Malapit Nang Mag-expire ang Sesyon",
        "Your portal access expires in" => "Mae-expire ang iyong access sa portal sa",
        "minutes. Visit the front desk to extend your stay." => "minuto. Pumunta sa front desk upang magpahaba ng iyong paninirahan.",
        "Dismiss"                   => "Isara",
        "Back to home"              => "Bumalik sa home",
        "Collect Room Payment"      => "Kolektahin ang Bayad sa Silid",
        "Confirm Collection"        => "Kumpirmahin ang Bayad",
        "Amount due"                => "Halagang Babayaran",
        "Amount received"           => "Halagang Natanggap",
        "Guest ID or room no."      => "ID ng bisita o bilang ng silid",
        "Guest portal navigation"   => "Nabigasyon ng guest portal",
        "Mobile portal navigation"  => "Nabigasyon ng portal sa mobile",
        "Primary navigation"        => "Pangunahing nabigasyon",
        "Review invitation"         => "Imbitasyon sa pagpuna",
        "Dismiss review invitation" => "Isara ang imbitasyon sa pagpuna",
        "Contact"                   => "Kontakin",
        "Privacy Policy"            => "Patakaran sa Pribasiya",

        // ── Body copy, empty states and help text ────────────────────────
        "No messages yet."          => "Wala pang mensahe.",
        "Unauthorized access, misuse, or tampering with system data is strictly prohibited and may result in disciplinary action." =>
            "Mahigpit na ipinagbabawal ang walang pahintulot na pagpasok, maling paggamit, o pagbabago ng datos ng sistema at maaaring maging dahilan ng disiplinaryong aksyon.",
        "Tap the chat icon at the bottom-right of your portal" =>
            "I-tap ang chat icon sa kanang ibaba ng iyong portal",
        "Extensions can be requested through the" => "Maaaring hilingin ang mga extension sa pamamagitan ng",
        "Request any service through the" => "Hilingin ang anumang serbisyo sa pamamagitan ng",
        "Select a conversation"     => "Pumili ng usapan",
        "Select a guest from the left to view their messages." =>
            "Pumili ng bisita sa kaliwa upang makita ang kanyang mga mensahe.",
        "Front Desk replied to your chat." => "Sumagot ang front desk sa iyong chat.",
        "Anything the record should carry?" => "May nais ka bang idagdag sa talaan?",
        "Auto-deleted 60 days after archiving" => "Awtomatikong binubura 60 araw pagkatapos i-archive",
        "Invalid plate format. Use ABC 1234 or ABC-1234." =>
            "Maling format ng plaka. Gamitin ang ABC 1234 o ABC-1234.",
        "A reason is required."     => "Kailangan ng dahilan.",
        "Please select a room."     => "Pumili ng silid.",
        "Event saved successfully." => "Matagumpay na na-save ang event.",
        "Record successfully restored." => "Matagumpay na naibalik ang talaan.",
        "Item archived successfully." => "Matagumpay na na-archive ang item.",
        "Something went wrong."     => "May naganap na problema.",
        "This may take a few seconds" => "Maaaring magtagal ng ilang segundo",
        "Are you sure you want to archive this transaction?" =>
            "Sigurado ka bang gusto mong i-archive ang transaksyong ito?",
        "Archived transactions are hidden from the main view but can be restored if needed." =>
            "Nakatago sa pangunahing view ang mga naka-archive na transaksyon ngunit maaaring ibalik kung kailangan.",
        "This is you"               => "Ikaw ito",
        "Approved Total"            => "Kabuuang Aprubado",
        "Pending Value"             => "Halagang Nakabinbin",
        "Outstanding (Unpaid)"      => "Hindi Bayad (Outstanding)",
        "Collected"                 => "Nakolekta",
        "Completed"                 => "Tapos Na",
        "Expires"                   => "Mag-e-expire",
        "Issued by"                 => "Inilabas ni",
        "Deposit unpaid"            => "Hindi bayad ang deposit",
        "Deposit verified"          => "Na-verify ang deposit",
        "Guest arrived by vehicle"  => "Dumating ang bisita sakay ng sasakyan",
        "All transaction types"     => "Lahat ng uri ng transaksyon",
        "Showing total for applied filters:" => "Kabuuan para sa mga inilapat na filter:",
        "Total logged events"       => "Kabuuang naitalang event",
        "Generate portal access tokens at the front desk and hand them to guests." =>
            "Gumawa ng portal access token sa front desk at ibigay ito sa mga bisita.",
        "Housekeeping"              => "Paglilinis",
        "Laundry"                   => "Labada",
        "Room service requests"     => "Mga kahilingan sa serbisyo ng silid",
        "Review type: Service"      => "Uri ng puna: Serbisyo",
        "Review type: Restaurant"   => "Uri ng puna: Restaurant",
        "Review type: Hotel"        => "Uri ng puna: Hotel",
        "Review type: Amenities"    => "Uri ng puna: Pasilidad",
        "Today at a glance"         => "Ngayong araw sa isang tingin",
        "Live management snapshot"  => "Live na lagay ng pamamahala",
        "Month to date"             => "Mula umpisa ng buwan",
        "Room Availability Board"   => "Board ng Availability ng Silid",
        "Check-ins and total guests registered each month" =>
            "Mga check-in at kabuuang bisita bawat buwan",
        "Paid amounts grouped by transaction source" =>
            "Mga bayad na halaga ayon sa pinagmulan ng transaksyon",
        "Live split of every room right now" => "Live na hati ng bawat silid ngayon",
        "Approve unlocks once the deposit is verified." =>
            "Bubukas ang Approve kapag na-verify ang deposit.",
        "Keep username and password changes here so profile updates remain simple and predictable." =>
            "Dito nakalagay ang pagbabago sa username at password upang simple at malinaw ang pag-update ng profile.",
        "Access control is handled by the owner." => "Ang may-ari ang humahawak ng access control.",
        "The owner creates accounts and can deactivate or reactivate them in" =>
            "Ang may-ari ang gumagawa ng account at maaaring mag-deactivate o mag-reactivate sa",
        "Leave empty to keep the current image." => "Iwanang blangko upang panatilihin ang kasalukuyang larawan.",
        "Move page by page through the sidebar to monitor operations, update records, check schedules, and read guest feedback." =>
            "Isa-isahin ang mga pahina sa sidebar upang subaybayan ang operasyon, i-update ang mga talaan, tingnan ang iskedyul, at basahin ang mga puna ng bisita.",
        "Protect your login, verify entries before saving, and log out after use especially on shared front-desk devices." =>
            "Pangalagaan ang iyong login, i-verify ang mga entry bago i-save, at mag-log out pagkatapos gamitin lalo na sa pinagsasaluhang front-desk device.",
        "No user records match your search." => "Walang tumutugmang user sa iyong paghahanap.",
        "No rooms match your search." => "Walang tumutugmang silid sa iyong paghahanap.",
        "No amenities match your search." => "Walang tumutugmang pasilidad sa iyong paghahanap.",
        "No offers match your search." => "Walang tumutugmang alok sa iyong paghahanap.",
        "The number the guest sends the deposit to." => "Ang numerong padadalhan ng bisita ng deposit.",
        "Guest sees on the status page and in email" => "Nakikita ng bisita sa status page at sa email",
        "Unpaid booking is held for" => "Hinahawakan ang hindi bayad na booking ng",
        "Do not share login credentials." => "Huwag ibahagi ang login credentials.",
        "No smoking inside the rooms." => "Bawal manigarilyo sa loob ng mga silid.",
        "Visitors must register at the front desk." => "Kailangang magparehistro ng mga bisita sa front desk.",
        "Please do not remove hotel property from the premises." => "Huwag ilabas ang mga gamit ng hotel.",
        "Pets are not allowed unless prior arrangements have been made." =>
            "Hindi pinapayagan ang mga alagang hayop maliban kung may naunang kasunduan.",
        "Guests are responsible for any damage to room furnishings." =>
            "Mananagot ang bisita sa anumang pinsala sa mga gamit ng silid.",
        "Users are responsible for maintaining the confidentiality of their account credentials and for all activities conducted under their account." =>
            "Pananagutan ng gumagamit ang pagiging kompidensyal ng kanyang account credentials at ang lahat ng gawaing isinasagawa sa ilalim ng kanyang account.",
        "Your request will be reviewed and confirmed by the front desk." =>
            "Susuriin at kukumpirmahin ng front desk ang iyong kahilingan.",
        "You'll receive a confirmation once approved by the front desk." =>
            "Makakatanggap ka ng kumpirmasyon kapag inaprubahan ng front desk.",
        "NEW CHECK-OUT DATE"        => "BAGONG PETSA NG CHECK-OUT",
        "How was your stay?"        => "Kumusta ang iyong paninirahan?",
        "Unwind with a relaxing spa treatment arranged during your stay." =>
            "Magpahinga sa nakaka-relaks na spa treatment na isasaayos habang naninirahan ka.",
        "Enjoy refreshing access to the infinity pool during your stay." =>
            "Mag-enjoy sa infinity pool habang naninirahan ka.",
        "Enjoy a prepared meal delivered conveniently to your room." =>
            "Mag-enjoy sa inihandang pagkain na direktang dinadala sa iyong silid.",
        "Start your day with a convenient breakfast prepared by Marle Hotel." =>
            "Simulan ang iyong araw sa almusal na inihanda ng Marle Hotel.",
        "Special Occasions Setup"   => "Paghahanda para sa Espesyal na Okasyon",
        "All Rights Reserved."      => "Nakalaan ang Lahat ng Karapatan.",
        "Hotel Guide"               => "Gabay sa Hotel",
        "Language"                  => "Wika",

        // Copy that is shared by several screens (or by every screen at once),
        // so one translation serves the staff sidebar, the staff pages and the
        // guest portal alike.
        "These terms may be updated at any time without prior notice. Continued use of the system constitutes acceptance of changes." =>
            "Maaaring baguhin ang mga tuntuning ito anumang oras nang walang paunang abiso. Ang patuloy na paggamit ng sistema ay katumbas ng pagtanggap sa mga pagbabago.",
        "These terms may be updated at any time without prior notice." =>
            "Maaaring baguhin ang mga tuntuning ito anumang oras nang walang paunang abiso.",
        "This chat has been closed by staff. Please contact the front desk directly." =>
            "Isinara na ng staff ang chat na ito. Mangyaring makipag-ugnayan nang direkta sa front desk.",
        "Your feedback takes a minute. Submit a review and claim your free drink." =>
            "Isang minuto lang ang iyong puna. Mag-submit ng review at kunin ang libreng inumin mo.",
        "Everything you need to know for a comfortable stay." =>
            "Lahat ng kailangan mong malaman para sa komportableng paninirahan.",
        "Order in-room or reserve a table at the restaurant." =>
            "Umorder sa silid o magreserba ng mesa sa restaurant.",
        "Signature dishes by Executive Chef Reyes" => "Mga signature dish ni Executive Chef Reyes",
        "SPECIAL REQUESTS"          => "ESPESYAL NA KAHILINGAN",
        "In-Room Dining"            => "Kainan sa Silid",
        "Room Cleaning & Housekeeping" => "Paglilinis ng Silid at Housekeeping",
        "Laundry & Press"           => "Labada at Plantsa",
        "Medical / First Aid"       => "Medikal / Pangunang Lunas",
        "Mini-Bar / Snacks"         => "Mini-Bar / Meryenda",
        "Toiletries & Amenities"    => "Mga Gamit sa Banyo at Pasilidad",
        "Entertainment Services"    => "Mga Serbisyong Pang-aliw",
        "Extra Bedding / Furniture" => "Karagdagang Kobre-kama / Muwebles",
        "Wake-Up Call / Alarm"      => "Wake-Up Call / Alarma",
        "Special Occasions Setup"   => "Paghahanda para sa Espesyal na Okasyon",
        "Room Cleaning"             => "Paglilinis ng Silid",
        "Room Service"              => "Serbisyo sa Silid",
        "With Garage"               => "May Garage",
        "No actions"                => "Walang aksyon",
        "Maintenance is the only override stored here. For an occupied room choose" =>
            "Ang maintenance lang ang override na nakaimbak dito. Para sa okupadong silid, piliin ang",
        "to check the guest out and free the room; booked and cancelled statuses come from live booking data." =>
            "upang i-check out ang bisita at palayain ang silid; ang booked at cancelled na katayuan ay galing sa live na datos ng booking.",
    ],

    'fragments' => [
        // Full clauses that sit beside a value the table cannot know — the
        // welcome line wraps the username in a span, so the page renders
        // "Hello " + the name + ", welcome back!" as three separate pieces.
        ", welcome back!"           => ", maligayang pagbabalik!",
        "Welcome back!"             => "Maligayang pagbabalik!",
        // Clauses that wrap a live figure the table can never know.
        "awaiting approval"         => "naghihintay ng pag-apruba",
        "Pending Requests"          => "Mga Nakabinbing Kahilingan",
        "All Rights Reserved."      => "Nakalaan ang Lahat ng Karapatan.",
        "items selected"            => "item ang pinili",
        "rooms free"                => "silid ang bakante",
    ],

    'screens' => [
        'staff.dashboard' => [
            "Operations summary for managers" => "Buod ng operasyon para sa mga manager",
            "Operations summary for the owner" => "Buod ng operasyon para sa may-ari",
            "Front desk workspace summary" => "Buod ng trabaho ng front desk",
            "How the hotel is moving"   => "Kumusta ang takbo ng hotel",
            "Expected today"            => "Inaasahan ngayon",
            "Guests right now"          => "Mga bisita ngayon",
            "Registered today"          => "Naitala ngayon",
            "Check-outs today"          => "Mga check-out ngayon",
            "Billed today"              => "Singil ngayon",
            "Older unpaid balances"     => "Mga lumang hindi bayad na balanse",
            "Demand over time"          => "Pangangailangan sa paglipas ng panahon",
            "Where collections come from" => "Saan nanggagaling ang mga bayad",
            "What guests ask for"       => "Ang mga hinihiling ng bisita",
            "Room status mix"           => "Halo ng katayuan ng mga silid",
            "Awaiting action"           => "Naghihintay ng aksyon",
            "Collections"               => "Mga Bayad",
            "Inventory alerts"          => "Mga alerto sa imbentaryo",
            "Range"                     => "Saklaw",
            "No override (clear maintenance)" => "Walang override (alisin ang pag-aayos)",
            "Last 6 months"             => "Huling 6 na buwan",
            "Last 12 months"            => "Huling 12 buwan",
            "Last 24 months"            => "Huling 24 buwan",
            "Last 36 months"            => "Huling 36 buwan",
            "Hello"                     => "Kumusta",
            "All time"                  => "Buong Panahon",
            "Loading..."                => "Nilo-load...",
            "In-house"                  => "Nasa loob",
            "Arrivals"                  => "Mga Pagdating",
            "Check-ins"                 => "Mga Pag-check-in",
            "Departures"                => "Mga Pag-alis",
            "Overdue"                   => "Lampas sa takda",
            "New unpaid"                => "Bagong hindi bayad",
            "Front desk — today"        => "Front desk — ngayon",
            "Money — collections"       => "Pera — koleksyon",
            "Open (non-archived) requests grouped by type" =>
                "Mga bukas (hindi naka-archive) na kahilingan, pinangkat ayon sa uri",
            "booking requests waiting for review." =>
                "kahilingan sa booking na naghihintay ng pagsusuri.",
            "in unpaid balances remain open." =>
                "sa hindi pa bayad na balanse ang bukas pa.",
            "Start with the action queue on the left of each tile, then scan the grouped figures below for the front desk, collections and inventory in one pass." =>
                "Magsimula sa action queue sa kaliwa ng bawat tile, pagkatapos tingnan ang mga pinangkat na bilang sa ibaba para sa front desk, koleksyon at imbentaryo sa isang sulyap.",
            "Four focused views: demand over time, where collections come from, what guests are asking for, and how the rooms are split right now." =>
                "Apat na nakatuong view: ang pangangailangan sa paglipas ng panahon, saan nanggagaling ang koleksyon, ano ang hinihiling ng mga bisita, at paano nahahati ang mga silid ngayon.",
        ],

        'staff.requests' => [
            "Active"                    => "Aktibo",
            "Done"                      => "Tapos",
            "Keep as is"                => "Panatilihin",
            "Keep booking"              => "Ituloy ang booking",
            "Old conversations"         => "Mga lumang usapan",
            "Search room or message..." => "Maghanap ng silid o mensahe...",
            "From Date"                 => "Petsa ng Simula",
            "To Date"                   => "Petsa ng Pagtatapos",
            "Room #"                    => "Silid #",
            "Details (optional)"        => "Mga Detalye (opsyonal)",
            "Reason (required)"         => "Dahilan (kailangan)",
            "Start time"                => "Oras ng simula",
        "Anything the record should carry?" => "May nais ka bang idagdag sa talaan?",
            "All Statuses"              => "Lahat ng Katayuan",
            "All Types"                 => "Lahat ng Uri",
            "Approved - Paid"           => "Aprubado - Bayad",
            "Approved - Unpaid"         => "Aprubado - Hindi Bayad",
            "Choose a reason…"          => "Pumili ng dahilan…",
            "Duplicate booking"         => "Dobleng booking",
            "Guest called to cancel"    => "Tumawag ang bisita upang magkansela",
            "Guest cancelled at the desk" => "Kinansela ng bisita sa front desk",
            "Guest did not confirm the deposit" => "Hindi kinumpirma ng bisita ang deposit",
            "Guest emailed to cancel"   => "Nag-email ang bisita upang magkansela",
            "Guest unreachable"         => "Hindi maabot ang bisita",
            "Other"                     => "Iba pa",
            "Payment could not be completed" => "Hindi natuloy ang bayad",
            "E.g. Item unavailable at this time..." => "Hal. Hindi available ang item sa oras na ito...",
            "E.g. No rooms available for the requested dates..." => "Hal. Walang available na silid sa hinihiling na petsa...",
            "E.g. Room is already booked by another guest..." => "Hal. Nareserba na ang silid ng ibang bisita...",
            "E.g. Service not available at this time..." => "Hal. Hindi available ang serbisyo sa oras na ito...",
            "Verify the guest's reservation deposit first — Approve unlocks after that." =>
                "I-verify muna ang deposit ng bisita — dito lang magbubukas ang Approve.",
            "Please provide a reason. The guest will see this message." =>
                "Mangyaring magbigay ng dahilan. Makikita ng bisita ang mensaheng ito.",
            "Please provide a reason. The guest will receive this via email." =>
                "Mangyaring magbigay ng dahilan. Matatanggap ito ng bisita sa email.",
            "🔒 This conversation is closed" => "🔒 Sarado na ang usapang ito",
            "No extension requests yet." => "Wala pang kahilingan sa pagpapahaba.",
            "Select a type to load archived records." =>
                "Pumili ng uri upang i-load ang mga naka-archive na talaan.",
            "Are you sure you want to approve this booking?" =>
                "Sigurado ka bang gusto mong aprubahan ang booking na ito?",
            "Loading the booking…"      => "Nilo-load ang booking…",
            "Waiting for the guest to upload their GCash or bank receipt — a manager verifies the payment (against the bank/GCash statement) before this booking can be approved." =>
                "Naghihintay na i-upload ng bisita ang kanilang GCash o bank receipt — tinitiyak ng manager ang bayad (laban sa bank/GCash statement) bago maaprubahan ang booking na ito.",
            "This stay is already under way, so the booking can no longer be amended or cancelled from here. Manage the room from the Front Desk / Room Board instead." =>
                "Nagsimula na ang paninirahan, kaya hindi na maaaring baguhin o kanselahin ang booking dito. Pamahalaan ang silid sa Front Desk / Room Board.",
            "The check-in date for this booking has already arrived, so it can no longer be cancelled here. Settle the stay from the Front Desk / Checkout screen so it is closed properly." =>
                "Dumating na ang petsa ng check-in ng booking na ito, kaya hindi na ito makakansela dito. Isara ang paninirahan sa Front Desk / Checkout screen upang maayos itong maisara.",
        ],

        'staff.tokens' => [
            "1 day"                     => "1 araw",
            "3 hrs"                     => "3 oras",
            "6 hrs"                     => "6 oras",
            "12 hrs"                    => "12 oras",
            "Discount (Philippines)"    => "Diskwento (Pilipinas)",
            "Hours of Stay"             => "Tagal ng Pananatili",
            "Number of Persons"         => "Bilang ng Tao",
            "Plate Number"              => "Plaka ng Sasakyan",
            "No discount"               => "Walang diskwento",
            "Other (custom %)"          => "Iba pa (custom %)",
            "Stay"                      => "Paninirahan",
            "Expired At"                => "Nag-expire Noong",
            "Guest Token Manager"       => "Tagapamahala ng Guest Token",
            "Unpaid total"              => "Kabuuang hindi bayad",
            "Unpaid items"              => "Mga hindi bayad na item",
            "No plate recorded — walk-in." => "Walang naitalang plaka — walk-in.",
            "Select the guest's room and their length of stay. A check-in transaction is automatically logged." =>
                "Piliin ang silid ng bisita at ang tagal ng kanilang paninirahan. Awtomatikong naitala ang transaksyon ng check-in.",
        ],

        'staff.transactions' => [
            "Transaction History"       => "Kasaysayan ng Transaksyon",
            "Transaction History — Marle Hotel" => "Kasaysayan ng Transaksyon — Marle Hotel",
            "Transaction Detail"        => "Detalye ng Transaksyon",
            "Transaction Summary — All Time" => "Buod ng Transaksyon — Buong Panahon",
            "Archive transaction"       => "I-archive ang transaksyon",
            "Guest / Room"              => "Bisita / Silid",
            "Payment Status"            => "Katayuan ng Bayad",
            "Transaction Type"          => "Uri ng Transaksyon",
            "Transaction pages"         => "Mga pahina ng transaksyon",
            "Amount Due"                => "Halagang Babayaran",
            "Amount Received"           => "Halagang Natanggap",
            "Change"                    => "Sukli",
            "Change Received"           => "Natanggap na Sukli",
            "Collection Reference"      => "Sanggunian ng Bayad",
            "Date / Time"               => "Petsa / Oras",
            "Marle Hotel & Restaurant"  => "Marle Hotel at Restaurant",
            "Check-In"                  => "Pag-check-in",
            "Extension"                 => "Dagdag na Pamamalagi",
            "Service"                   => "Serbisyo",
            "All Types"                 => "Lahat ng Uri",
            "check in"                  => "pag-check in",
            "Overall Total — All Time"  => "Kabuuang Total — Buong Panahon",
            "Revenue by Room Type"      => "Kita ayon sa Uri ng Silid",
            "Revenue by Type"           => "Kita ayon sa Uri",
            "All transactions for the period — All Time" =>
                "Lahat ng transaksyon para sa panahon — Buong Panahon",
            "Grouped by room for front-desk payment collection." =>
                "Pinangkat ayon sa silid para sa pangongolekta ng bayad sa front desk.",
        ],

        'staff.inventory' => [
            "Add Inventory Item"        => "Magdagdag ng Item sa Imbentaryo",
            "Edit Item"                 => "I-edit ang Item",
            "Archive Item"              => "I-archive ang Item",
            "Save Item"                 => "I-save ang Item",
            "Update Item"               => "I-update ang Item",
            "Yes, Archive"              => "Oo, I-archive",
            "Clear selection"           => "Alisin ang pinili",
            "Cleaning"                  => "Paglilinis",
            "Linens"                    => "Mga Kobre-kama",
            "Toiletries"                => "Mga Gamit sa Banyo",
            "General"                   => "Pangkalahatan",
            "Equipment"                 => "Kagamitan",
            "Item Name"                 => "Pangalan ng Item",
            "Low Stock Threshold"       => "Hangganan ng Mababang Stock",
            "Show"                      => "Ipakita",
            "Optional short description" => "Opsyonal na maikling paglalarawan",
            "e.g. Bath Towels"          => "hal. Mga Tuwalya",
            "Select all"                => "Piliin lahat",
            "Decrease"                  => "Bawasan",
            "Increase"                  => "Dagdagan",
            "Total Items"               => "Kabuuang Item",
            "Total Quantity"            => "Kabuuang Dami",
            "Low Stock"                 => "Mababang Stock",
            "⚠ Low"                     => "⚠ Mababa",
            "This item will be moved to the archive." => "Ililipat ang item na ito sa archive.",
            "You can restore it anytime." => "Maaari mo itong ibalik anumang oras.",
        ],

        'staff.guests' => [
            "Guest Record | Hotel Admin" => "Talaan ng Bisita | Hotel Admin",
            "Edit Guest"                => "I-edit ang Bisita",
            "Add Guest"                 => "Magdagdag ng Bisita",
            "Update"                    => "I-update",
            "Search records"            => "Maghanap sa mga talaan",
            "Filter by Date From"       => "Salain ayon sa Petsa ng Simula",
            "No. of persons"            => "Bilang ng tao",
            "Hours of stay"             => "Oras ng paninirahan",
            "If applicable"             => "Kung naaangkop",
            "Select Room"               => "Piliin ang Silid",
            "Select Transportation"     => "Piliin ang Sasakyan",
            "Customer ID"               => "ID ng Bisita",
            "Date From"                 => "Petsa ng Simula",
            "Date To"                   => "Petsa ng Pagtatapos",
            "Plate No."                 => "Plaka",
            "Transportation"            => "Sasakyan",
            "Additional"                => "Karagdagan",
            "Towel"                     => "Tuwalya",
            "Blanket"                   => "Kumot",
            "Pillow"                    => "Unan",
            "Toiletries"                => "Mga Gamit sa Banyo",
            "Clear maintenance"         => "Alisin ang pag-aayos",
            "Guest record pages"        => "Mga pahina ng talaan ng bisita",
            "Hotel logo"                => "Logo ng hotel",
            "None"                      => "Wala",
            "By Vehicle"                => "Sa Sasakyan",
            "No guest records found."   => "Walang nahanap na talaan ng bisita.",
            "Are you sure you want to archive this record?" =>
                "Sigurado ka bang gusto mong i-archive ang talaang ito?",
        ],

        'staff.calendar' => [
            "Calendar"                  => "Kalendaryo",
            "Manage Event"              => "Pamahalaan ang Event",
            "Save Event"                => "I-save ang Event",
            "Delete Event"              => "Burahin ang Event",
            "Event name"                => "Pangalan ng event",
            "Title"                     => "Pamagat",
            "Date From"                 => "Petsa ng Simula",
            "Date To"                   => "Petsa ng Pagtatapos",
            "Event Color"               => "Kulay ng Event",
            "Optional notes"            => "Opsyonal na tala",
            "Manual Event (Gold)"       => "Manwal na Event (Ginto)",
            "Booking Type"              => "Uri ng Booking",
            "Room No."                  => "Bilang ng Silid",
            "Schedule"                  => "Iskedyul",
            "Yes (5)"                   => "Oo (5)",
            "Manual Events"             => "Mga Manwal na Event",
            "Bookings (read-only)"      => "Mga Booking (read-only)",
            "Booking events are read-only. Manage in" =>
                "Ang mga booking event ay read-only. Pamahalaan sa",
            "Are you sure you want to delete this event?" =>
                "Sigurado ka bang gusto mong burahin ang event na ito?",
            "This calendar is view-only for employees." =>
                "View-only ang kalendaryong ito para sa mga empleyado.",
            // Walk-in bookings (2026-10-17): a guest at the desk paying cash.
            "Walk-in booking"           => "Walk-in na booking",
            "A guest at the desk, paying the reservation deposit in cash." =>
                "Bisitang dumating sa desk, nagbabayad ng deposito sa cash.",
            "Guest name"                => "Pangalan ng bisita",
            "Phone"                     => "Telepono",
            "Stay type"                 => "Uri ng paglagi",
            "Overnight"                 => "Magdamag",
            "Hourly"                    => "Kada oras",
            "Arrival date"              => "Petsa ng pagdating",
            "Departure date"            => "Petsa ng pag-alis",
            "Arrival time"              => "Oras ng pagdating",
            "Departure time"            => "Oras ng pag-alis",
            "Room type"                 => "Uri ng silid",
            "Guests"                    => "Mga bisita",
            "Special requests"          => "Mga espesyal na kahilingan",
            "Booking total"             => "Kabuuang halaga",
            "Deposit due now"           => "Deposito ngayon",
            "Method"                    => "Paraan ng bayad",
            "Cash at the front desk"    => "Cash sa front desk",
            "Record cash booking"       => "Itala ang cash booking",
            "11 digits, e.g. 09171234567" => "11 numero, hal. 09171234567",
            "Exactly 11 digits — the same rule the booking website uses." =>
                "Eksaktong 11 na numero — katulad ng tuntunin sa booking website.",
            "The domain is really checked — a mistyped address would never receive the booking email." =>
                "Sinisiyasat ang domain — ang maling email ay hindi makakatanggap ng email ng booking.",
        ],

        'staff.reviews' => [
            "Ratings"                   => "Mga Rating",
            "Latest reviews"            => "Mga pinakabagong puna",
        "Date range"                => "Saklaw ng petsa",
            "Language filter"           => "Filter ng wika",
            "All Category"              => "Lahat ng Kategorya",
            "All categories"            => "Lahat ng kategorya",
            "All dates"                 => "Lahat ng petsa",
            "This week"                 => "Ngayong linggo",
            "This month"                => "Ngayong buwan",
            "This year"                 => "Ngayong taon",
            "All languages"             => "Lahat ng wika",
            "English"                   => "Ingles",
            "Amenities"                 => "Mga Pasilidad",
            "Service"                   => "Serbisyo",
            "Groq is analyzing your reviews…" => "Sinusuri ng Groq ang iyong mga puna…",
        ],

        'staff.settings' => [
            "Account Settings"          => "Mga Setting ng Account",
            "Everything important stays in one place." => "Nasa isang lugar ang lahat ng mahalaga.",
            "Employee tools stay focused here." => "Dito nakatuon ang mga tool ng empleyado.",
            "Add New User"              => "Magdagdag ng Bagong User",
            "Add User"                  => "Magdagdag ng User",
            "Deactivate"                => "I-deactivate",
            "Front Desk Accounts"       => "Mga Account ng Front Desk",
            "Front Desk Quick Reference" => "Mabilisang Sanggunian ng Front Desk",
            "Reference Tools"           => "Mga Tool na Sanggunian",
            "Maintenance Shortcuts"     => "Mga Shortcut sa Maintenance",
            "Rooms Section Settings"    => "Mga Setting ng Seksyon ng Silid",
            "Offers Section Settings"   => "Mga Setting ng Seksyon ng Alok",
            "Exclusive Amenities Section Settings" => "Mga Setting ng Seksyon ng Eksklusibong Pasilidad",
            "Edit Room Details"         => "I-edit ang Detalye ng Silid",
            "Edit Amenity"              => "I-edit ang Pasilidad",
            "Edit Offer"                => "I-edit ang Alok",
            "Save deposit settings"     => "I-save ang mga setting ng deposit",
            "Deposit required to confirm" => "Kailangan ng deposit upang kumpirmahin",
            "Hold window while unpaid"  => "Tagal ng paghawak habang hindi bayad",
            "Bank transfer details"     => "Detalye ng bank transfer",
            "GCash number"              => "Numero ng GCash",
            "Current Password"          => "Kasalukuyang Password",
            "New Password"              => "Bagong Password",
            "Confirm New Password"      => "Kumpirmahin ang Bagong Password",
            "Replace Image (Optional)"  => "Palitan ang Larawan (Opsyonal)",
            "Change"                    => "Palitan",
            "Room Name"                 => "Pangalan ng Silid",
            "User Type"                 => "Uri ng User",
            "Select a role"             => "Pumili ng tungkulin",
            "Employee"                  => "Empleyado",
            "Enter new username"        => "Ilagay ang bagong username",
            "Leave blank to keep current" => "Iwanang blangko upang panatilihin ang kasalukuyan",
            "Repeat new password"       => "Ulitin ang bagong password",
            "Required to change password" => "Kailangan upang palitan ang password",
            "Users"                     => "Mga User",
            "Reservation Deposit"       => "Deposit sa Reserbasyon",
            "Amenities"                 => "Mga Pasilidad",
            "Offers"                    => "Mga Alok",
            "Profile Picture"           => "Larawan ng Profile",
            "Room preview"              => "Preview ng silid",
            "A short reference while you work" => "Maikling sanggunian habang nagtatrabaho",
            "2. Requests"               => "2. Serbisyo",
            "3. Calendar"               => "3. Kalendaryo",
            "4. Inventory"              => "4. Imbentaryo",
            "5. Guest Record"           => "5. Talaan ng Bisita",
            "6. Reviews"                => "6. Pagsusuri at Rekomendasyon",
            "7. Guest Tokens — Occupied Rooms" => "7. Token ng Bisita — Okupadong Silid",
            "8. Collect Payment (front-desk scope)" => "8. Kolektahin ang Bayad (saklaw ng front desk)",
            "9. Settings"               => "9. Pagsasaayos",
            "Edit amenity Breakfast Package" => "I-edit ang pasilidad na Breakfast Package",
            "Edit amenity In-Room Dining" => "I-edit ang pasilidad na In-Room Dining",
            "Edit amenity Infinity Pool Access" => "I-edit ang pasilidad na Infinity Pool Access",
            "Edit amenity Spa Treatment" => "I-edit ang pasilidad na Spa Treatment",
            "Edit offer Celebration Package 1" => "I-edit ang alok na Celebration Package 1",
            "Edit offer Celebration Package 2" => "I-edit ang alok na Celebration Package 2",
            "Edit offer Celebration Package 3" => "I-edit ang alok na Celebration Package 3",
            "Edit room Family"          => "I-edit ang silid na Family",
            "Edit room Suite"           => "I-edit ang silid na Suite",
            "Edit room With Garage"     => "I-edit ang silid na With Garage",
            "Edit room Without Garage"  => "I-edit ang silid na Without Garage",

            // Help copy. The manager, the owner and the front desk each get
            // their own paragraphs on the same screen, so they share one screen id.
            "Your Account"              => "Iyong Account",
            "Purpose:"                  => "Layunin:",
            "Main actions:"             => "Pangunahing aksyon:",
            "Important reminders:"      => "Mahahalagang paalala:",
            "Use this page to keep your account updated." =>
                "Gamitin ang pahinang ito upang panatilihing updated ang iyong account.",
            "Update your profile picture, username, and password without changing how the page works behind the scenes." =>
                "I-update ang iyong profile picture, username, at password nang hindi binabago ang takbo ng pahina.",
            "An overview of the Front Desk (employee) accounts used at reception. This list is read-only — the owner creates accounts and handles activation, deactivation and access control." =>
                "Pangkalahatang tingin sa mga account ng Front Desk (empleyado) na ginagamit sa reception. Read-only ang listahang ito — ang may-ari ang gumagawa ng account at humahawak ng activation, deactivation at access control.",
            ". If someone has left the hotel, ask the owner to deactivate their account — they are signed out immediately and cannot sign in again." =>
                ". Kung may umalis na sa hotel, ipa-deactivate sa may-ari ang account nila — agad silang isinasara at hindi na makakapasok muli.",
            "Keep the documentation and references you need while you work close by." =>
                "Panatilihing malapit ang dokumentasyon at mga sanggunian na kailangan mo habang nagtatrabaho.",
            "Manage account details, user access, system tools, and homepage content from one clean workspace." =>
                "Pamahalaan ang detalye ng account, access ng user, mga tool ng sistema, at nilalaman ng homepage sa isang maayos na workspace.",
            "Use this page to keep your account updated, manage user access, and maintain the public homepage sections without jumping between separate screens." =>
                "Gamitin ang pahinang ito upang panatilihing updated ang account, pamahalaan ang access ng user, at alagaan ang mga seksyon ng pampublikong homepage nang hindi lumilipat-lipat ng screen.",
            "Create additional accounts with the right role assignment from the same control panel." =>
                "Gumawa ng karagdagang account na may tamang tungkulin mula sa parehong control panel.",
            "Review accounts and roles, and deactivate anyone who has left the hotel so they lose access immediately." =>
                "Suriin ang mga account at tungkulin, at i-deactivate ang sinumang umalis na sa hotel upang agad mawala ang kanilang access.",
            "Keep recovery and documentation actions close by with clearer visual entry points." =>
                "Panatilihing malapit ang mga aksyon sa recovery at dokumentasyon sa mas malinaw na entry point.",
            "Update the room names and room imagery shown on the homepage without leaving the settings screen." =>
                "I-update ang mga pangalan at larawan ng silid na nakikita sa homepage nang hindi lumalabas sa settings screen.",
            "Refine amenity titles and descriptions in a cleaner table with faster edit actions." =>
                "Ayusin ang mga pamagat at paglalarawan ng pasilidad sa mas malinaw na talahanayan na may mas mabilis na pag-edit.",
            "Keep package titles and descriptions polished with a simpler edit workflow." =>
                "Panatilihing maayos ang mga pamagat at paglalarawan ng package sa mas simpleng paraan ng pag-edit.",
            "Decide how much of a booking the guest pays before you confirm the room, and tell them exactly where to send it. These details appear on the booking status page and inside every confirmation email." =>
                "Itakda kung magkano ang babayaran ng bisita bago kumpirmahin ang silid, at sabihin nang eksakto kung saan ito ipapadala. Lumalabas ang mga detalyeng ito sa booking status page at sa bawat confirmation email.",
            "How long an unpaid booking keeps the room before it is released. Run" =>
                "Gaano katagal hinahawakan ng hindi bayad na booking ang silid bago ito palayain. Patakbuhin ang",
            "hourly so the release happens on time." => "bawat oras upang tumama ang pagpapalaya.",
            "Changing the percentage or the hold window only affects" =>
                "Ang pagbabago sa porsyento o sa hold window ay nakakaapekto lang sa",
            "Use this page to update your account and open employee-only help resources without leaving the front-desk workspace." =>
                "Gamitin ang pahinang ito upang i-update ang iyong account at buksan ang mga help resource na para sa empleyado lang nang hindi lumalabas sa workspace ng front desk.",
            "Update your profile picture, username, and password. Catalog and user management stay with the manager and owner accounts." =>
                "I-update ang iyong profile picture, username, at password. Ang pamamahala ng catalog at user ay nasa account ng manager at may-ari.",
            "Open employee guidance and day-to-day support references from one place." =>
                "Buksan ang gabay ng empleyado at mga sanggunian sa araw-araw na gawain mula sa isang lugar.",
            "This is the condensed version: what each Front Desk page is for and the reminders that keep records accurate. For the full guide — sign-in rules, session limits, the guest portal journey, payment collection and the FAQ — open" =>
                "Ito ang pinaikling bersyon: para saan ang bawat pahina ng Front Desk at ang mga paalala upang manatiling tama ang mga talaan. Para sa buong gabay — mga patakaran sa sign-in, limitasyon ng sesyon, paglalakbay sa guest portal, pangongolekta ng bayad at ang FAQ — buksan ang",
            "in the sidebar."          => "sa sidebar.",
            "Work only within the employee pages shown in the sidebar. Follow on-screen controls and keep guest and room records accurate." =>
                "Gumawa lamang sa loob ng mga pahina ng empleyado na nakikita sa sidebar. Sundin ang mga kontrol sa screen at panatilihing tama ang mga talaan ng bisita at silid.",
            "Use the dashboard as your first overview of current operations, room activity, and quick metrics." =>
                "Gamitin ang dashboard bilang unang pagtingin sa kasalukuyang operasyon, galaw ng mga silid, at mabilisang sukatan.",
            "Review the summary cards, watch the room board, and use the dashboard to spot rooms or activity that need attention." =>
                "Suriin ang mga summary card, tingnan ang room board, at gamitin ang dashboard upang makita ang mga silid o gawaing nangangailangan ng pansin.",
            "Treat the dashboard as a monitoring page. Use it to identify where to go next, then open the correct module for detailed updates." =>
                "Ituring ang dashboard bilang monitoring page. Gamitin ito upang malaman kung saan susunod na pupunta, pagkatapos buksan ang tamang module para sa detalyadong pag-update.",
            "Manage guest requests in one place, including booking, extension, service, and food-related requests." =>
                "Pamahalaan ang mga kahilingan ng bisita sa isang lugar, kasama ang booking, pagpapahaba, serbisyo, at mga kahilingan sa pagkain.",
            "Review pending requests, open request details, update statuses, respond through the available request flow, and monitor room-specific activity and archived request history where shown." =>
                "Suriin ang mga nakabinbing kahilingan, buksan ang detalye, i-update ang katayuan, tumugon sa pamamagitan ng available na request flow, at subaybayan ang galaw ng bawat silid at ang naka-archive na kasaysayan ng kahilingan kung nakikita.",
            "Read request details carefully before updating status. Make sure the action you choose matches the guest concern or service being requested." =>
                "Basahin nang mabuti ang detalye ng kahilingan bago i-update ang katayuan. Tiyaking tugma ang pipiliin mong aksyon sa hinaing o serbisyong hinihiling ng bisita.",
            "View schedules and upcoming items in calendar format so front-desk activity stays organized." =>
                "Tingnan ang mga iskedyul at darating na gawain sa format na kalendaryo upang manatiling organisado ang gawain ng front desk.",
            "Check the calendar view, switch between available calendar displays, and open event details when you need more context." =>
                "Tingnan ang calendar view, magpalipat-lipat sa mga available na display, at buksan ang detalye ng event kapag kailangan mo ng mas malinaw na konteksto.",
            "Use the calendar to confirm timing and schedule visibility. If an event looks incorrect, verify details against the source record before acting on it." =>
                "Gamitin ang kalendaryo upang kumpirmahin ang oras at ang nakikitang iskedyul. Kung mukhang mali ang isang event, tiyakin ang detalye sa pinagmulang talaan bago kumilos.",
            "Track stock levels and maintain the list of inventory items used in operations." =>
                "Subaybayan ang antas ng stock at panatilihin ang listahan ng mga item na ginagamit sa operasyon.",
            "Search items, filter for low stock, add items, review quantities by category, export CSV data, and archive selected records when needed." =>
                "Maghanap ng item, salain ang mababang stock, magdagdag ng item, suriin ang dami ayon sa kategorya, i-export ang CSV, at i-archive ang mga piniling talaan kung kailangan.",
            "Double-check item names, categories, and quantities before saving. Watch low-stock indicators so supplies can be addressed early." =>
                "Dobleng tiyakin ang pangalan, kategorya, at dami ng item bago i-save. Bantayan ang low-stock indicator upang maagapan ang mga supply.",
            "Maintain guest stay records, room assignments, check-in information, and status tracking." =>
                "Panatilihin ang mga talaan ng paninirahan, takdang silid, impormasyon ng check-in, at pagsubaybay sa katayuan.",
            "Search and filter records, export CSV data, review stay dates and additional items, and update a stay's status (such as checking a guest out early). Adding or editing guest entries is a manager-and-owner action." =>
                "Maghanap at magsalà ng mga talaan, i-export ang CSV, suriin ang mga petsa ng paninirahan at karagdagang item, at i-update ang katayuan ng paninirahan (halimbawa, maagang check-out). Ang pagdagdag o pag-edit ng talaan ng bisita ay aksyon ng manager at may-ari.",
            "Confirm room number, date ranges, guest count, and status before saving. Incorrect guest records can affect requests, room status, and reporting." =>
                "Kumpirmahin ang numero ng silid, saklaw ng petsa, bilang ng bisita, at katayuan bago i-save. Ang maling talaan ng bisita ay nakakaapekto sa mga kahilingan, katayuan ng silid, at ulat.",
            "Monitor guest feedback, ratings, and sentiment summaries to understand service performance." =>
                "Subaybayan ang puna, rating, at buod ng damdamin ng mga bisita upang maunawaan ang kalidad ng serbisyo.",
            "Read submitted reviews, use page filters and counts, check rating summaries, and review sentiment or feedback trends shown on the page." =>
                "Basahin ang mga isinumiteng review, gamitin ang mga filter at bilang sa pahina, tingnan ang buod ng rating, at suriin ang takbo ng damdamin o puna na nakikita sa pahina.",
            "Use reviews for awareness and service improvement. Do not treat summary charts alone as the full picture without reading the actual guest comments." =>
                "Gamitin ang mga review para sa kaalaman at pagpapabuti ng serbisyo. Huwag ituring na kumpletong larawan ang mga chart kung hindi babasahin ang aktwal na komento ng bisita.",
            "See which rooms are occupied, issue portal access tokens, and collect the room's bill." =>
                "Tingnan kung aling mga silid ang okupado, magbigay ng portal access token, at kolektahin ang bayad ng silid.",
            "Select the correct room, enter stay details such as persons and hours of stay, record vehicle plate information when available, and generate the access token for the guest. Each room card is titled with its number (for example" =>
                "Piliin ang tamang silid, ilagay ang detalye ng paninirahan tulad ng bilang ng tao at oras ng paninirahan, itala ang plaka ng sasakyan kung meron, at gumawa ng access token para sa bisita. Ang bawat room card ay may pamagat na numero nito (halimbawa",
            ") and shows the room's unpaid total, its settled and unsettled item counts, and a breakdown of check-in, extension, service and food charges." =>
                ") at ipinapakita ang kabuuang hindi bayad ng silid, ang bilang ng bayad at hindi pa bayad na item, at ang hati ng bayad sa check-in, pagpapahaba, serbisyo at pagkain.",
            "Make sure the selected room is correct and available before issuing a token. Incorrect room or stay information can cause guest access problems." =>
                "Tiyaking tama at available ang napiling silid bago magbigay ng token. Ang maling impormasyon ng silid o paninirahan ay maaaring maging problema sa access ng bisita.",
            "Record what the guest pays at the counter against the room's unpaid balance." =>
                "Itala ang bayad ng bisita sa counter laban sa hindi pa bayad na balanse ng silid.",
            "on the room card, check the amount due the system calculated, type the amount received, confirm the change shown, and submit once." =>
                "sa room card, tingnan ang halagang dapat bayaran na kinakalkula ng sistema, ilagay ang natanggap na halaga, kumpirmahin ang suklì, at isumite nang isang beses.",
            "Underpayment is refused, the amount due is fixed by the system, and the button locks while saving so a collection cannot be recorded twice. The Front Desk has" =>
                "Tinatanggihan ang kulang na bayad, ang halagang dapat bayaran ay ayon sa sistema, at naka-lock ang button habang nagse-save upang hindi maitala nang dalawang beses ang isang koleksyon. Ang Front Desk ay may",
            "Transactions ledger and" => "Transactions ledger at",
            "report export — itemized history and printed reports belong to the manager and owner." =>
                "pag-export ng ulat — ang itemized history at mga naka-print na ulat ay para sa manager at may-ari.",
            "Access employee support tools and system guidance from one place." =>
                "Buksan ang mga tool sa suporta ng empleyado at gabay ng sistema mula sa isang lugar.",
            "Open this employee user manual, review terms of use, and use any settings tools currently shown on the page." =>
                "Buksan ang user manual ng empleyado, suriin ang mga tuntunin sa paggamit, at gamitin ang anumang tool sa setting na nakikita sa pahina.",
            "Settings should support your daily work, not replace record verification. Always confirm changes or actions in the proper operational page before finalizing them." =>
                "Ang settings ay dapat sumuporta sa araw-araw na gawain, hindi kapalit ng pagpapatunay ng talaan. Palaging kumpirmahin ang mga pagbabago o aksyon sa tamang pahina ng operasyon bago tapusin.",
            "If a page contains important guest, room, inventory, or payment information, slow down before saving. Small entry mistakes can affect multiple parts of the system." =>
                "Kung ang pahina ay may mahalagang impormasyon tungkol sa bisita, silid, imbentaryo, o bayad, dahan-dahanin bago i-save. Ang maliliit na pagkakamali sa paglalagay ay nakakaapekto sa maraming bahagi ng sistema.",
        ],

        'staff.systemlog' => [
            "System Log — Marle Hotel"  => "Aktibidad ng Sistema — Marle Hotel",
            "User, reason, IP…"         => "User, dahilan, IP…",
            "Actor"                     => "Gumagamit",
            "When"                      => "Kailan",
            "Outcome"                   => "Resulta",
            "Any action"                => "Kahit anong aksyon",
            "All outcomes"              => "Lahat ng resulta",
            "All roles"                 => "Lahat ng tungkulin",
            "Guest / System"            => "Bisita / Sistema",
            "Owner"                     => "May-ari",
            "System log pages"          => "Mga pahina ng aktibidad ng sistema",
        ],

        // ── The guest portal. Its chrome (navbar, countdown, privacy modal,
        //    footer, chat widget) is shared through 'common'; what follows is the
        //    five screens' own copy. Dish and service names come from the
        //    database and are shown exactly as the restaurant and the front desk
        //    wrote them. ──────────────────────────────────────────────────────

        'portal.home' => [
            "Guest Portal"              => "Portal ng Bisita",
            "WELCOME, VALUED GUEST"     => "MALIGAYANG PAGDATING, MINAMAHAL NA BISITA",
            "Everything you need during your stay is just a tap away. Browse services, extend your booking, or explore our menu." =>
                "Isang tap lang ang lahat ng kailangan mo habang naninirahan ka. Tingnan ang mga serbisyo, pahabain ang booking, o silipin ang aming menu.",
            "Check-in active"           => "Aktibo ang pag-check-in",
            "YOUR ROOM"                 => "ANG IYONG SILID",
            "STAY"                     => "PANINIRAHAN",
            "Time left"                 => "Natitirang oras",
            "Your running total"        => "Ang iyong kabuuang bayad",
            "Unpaid charges only. Paid items are not shown." =>
                "Mga hindi pa bayad lang. Hindi ipinapakita ang mga bayad na.",
            "Request"                   => "I-request",
            "Add Hours"                 => "Magdagdag ng Oras",
            "Order Food"                => "Umorder ng Pagkain",
            "Info"                      => "Impormasyon",
            "Hotel Services"            => "Mga Serbisyo ng Hotel",
            "See all"                   => "Tingnan lahat",
            "Relaxing spa treatments in your room" => "Nakaka-relaks na spa sa loob ng iyong silid",
            "Full deep-clean and fresh linen service." => "Malinis na malinis at sariwang kobre-kama.",
            "Meals delivered straight to your room." => "Direktang dinadala sa silid mo ang pagkain.",
            "/ per session."            => "/ bawat sesyon.",
            "/ per session"             => "/ bawat sesyon",
            "/ delivery fee"            => "/ bayad sa delivery",
            "Request Deluxe Bath and Spa" => "I-request ang Deluxe Bath and Spa",
            "Request Room Cleaning"     => "I-request ang Paglilinis ng Silid",
            "Request Room Service"      => "I-request ang Serbisyo sa Silid",
            "Tell us what you think!"   => "Ibahagi ang iyong opinyon!",
        "Everything you need to know for a comfortable stay." =>
            "Lahat ng kailangan mong malaman para sa komportableng paninirahan.",
        "Order in-room or reserve a table at the restaurant." =>
            "Umorder sa silid o magreserba ng mesa sa restaurant.",
        "Signature dishes by Executive Chef Reyes" => "Mga signature dish ni Executive Chef Reyes",
        "How was your stay?"        => "Kumusta ang iyong paninirahan?",
        "Your feedback takes a minute. Submit a review and claim your free drink." =>
            "Isang minuto lang ang iyong puna. Mag-submit ng review at kunin ang libreng inumin mo.",
            "Leave us a"                => "Mag-iwan ng",
            "review"                    => "puna",
            "to get a free drink."      => "upang makakuha ng libreng inumin.",
            "Review"                    => "Mag-review",
            "Contact"                   => "Kontakin",
        ],

        'portal.services' => [
            "Hotel Services"            => "Mga Serbisyo ng Hotel",
            "What can we do"            => "Ano ang maipaglilingkod namin",
            "for you today?"            => "sa iyo ngayon?",
            "My Requests"               => "Aking mga Kahilingan",
            "Wellness"                  => "Kalusugan",
            "Dining"                    => "Kainan",
            "Housekeeping"              => "Paglilinis",
            "Laundry"                   => "Labada",
            "Tap to see more"           => "I-tap upang makita pa",
            "Entertainment Services"    => "Mga Serbisyong Pang-aliw",
            "Extra Bedding / Furniture" => "Karagdagang Kobre-kama / Muwebles",
            "In-Room Dining"            => "Kainan sa Silid",
            "Laundry & Press"           => "Labada at Plantsa",
            "Medical / First Aid"       => "Medikal / Pangunang Lunas",
            "Mini-Bar / Snacks"         => "Mini-Bar / Meryenda",
            "Room Cleaning & Housekeeping" => "Paglilinis ng Silid at Housekeeping",
            "Special Occasions Setup"   => "Paghahanda para sa Espesyal na Okasyon",
            "Toiletries & Amenities"    => "Mga Gamit sa Banyo at Pasilidad",
            "Wake-Up Call / Alarm"      => "Wake-Up Call / Alarma",
            "Deluxe Bath & Spa"         => "Deluxe Bath at Spa",
            "Room Cleaning"             => "Paglilinis ng Silid",
            "Room Service"              => "Serbisyo sa Silid",
            "AVAILABLE NOW"             => "AVAILABLE NA NGAYON",
            "/ on request"              => "/ kung hihilingin",
            "Request a service and we'll be right there." =>
                "Mag-request ng serbisyo at agad kaming pupunta.",
            "No service requests yet. Tap" => "Wala pang kahilingan sa serbisyo. I-tap ang",
            "on any service to request it." => "sa alinmang serbisyong nais mong i-request.",
        ],

        'portal.extend' => [
            "Extend Stay"               => "Pahabain ang Paninirahan",
            "Stay a little longer"      => "Magdagdag ng oras ng pamamalagi",
            "Send Extension Request"    => "Ipadala ang Kahilingan sa Pagdaragdag",
            "Submit extension request"  => "Ipasa ang kahilingan sa pagdaragdag",
            "1h"                        => "1 oras",
            "2h"                        => "2 oras",
            "3h"                        => "3 oras",
            "4h"                        => "4 oras",
            "6h"                        => "6 oras",
            "8h"                        => "8 oras",
            "12h"                       => "12 oras",
            "E.g. early check-in, dietary restrictions, etc." =>
                "Hal. maagang pag-check-in, paghihigpit sa pagkain, atbp.",
            "BOOKING EXTENSION"         => "PAGPAPAHABA NG BOOKING",
            "CURRENT BOOKING"           => "KASALUKUYANG BOOKING",
            "ADDITIONAL HOURS"          => "KARAGDAGANG ORAS",
            "Estimated Total"           => "Tinatayang Kabuuan",
            "hours"                     => "oras",
            "booked"                    => "nakareserba",
        ],

        'portal.menu' => [
            "Restaurant Menu"           => "Menu ng Restaurant",
            "Curated dining,"           => "Piniling pagkain,",
            "served with care."         => "inihahanda nang may pagmamahal.",
            "Chef's Recommendations"    => "Mga Rekomendasyon ng Chef",
            "Order Summary"             => "Buod ng Order",
            "Review Your Order"         => "Tingnan ang Iyong Order",
            "Review Order"              => "Tingnan ang Order",
            "CONFIRM REQUEST"           => "KUMPIRMAHIN ANG KAHILINGAN",
            "Order request sent"        => "Naipadala na ang order",
            "Appetizer"                 => "Pampagana",
            "Dessert"                   => "Panghimagas",
            "Drinks"                    => "Inumin",
            "Main"                      => "Pangunahing Pagkain",
            "Bestseller"                => "Pinakabenta",
            "Search menu"               => "Maghanap sa menu",
            "Search dishes..."          => "Maghanap ng pagkain...",
            "Quantity"                  => "Dami",
            "Special Request"           => "Espesyal na Kahilingan",
            "E.g. allergies, dietary restrictions, additional sauce, etc." =>
                "Hal. allergy, paghihigpit sa pagkain, karagdagang sarsa, atbp.",
            "E.g. no onions, extra sauce, well done..." =>
                "Hal. walang sibuyas, dagdag na sarsa, well done...",
            "No food orders yet. Tap"   => "Wala pang order sa pagkain. I-tap ang",
            "on any dish to order it."  => "sa alinmang pagkaing nais mong i-order.",
            "Your order has been sent to the front desk." =>
                "Naipadala na ang iyong order sa front desk.",
        ],

        'portal.guide' => [
            "Hotel Guide - Marle Hotel" => "Gabay sa Hotel - Marle Hotel",
            "Welcome to Marle Hotel"    => "Maligayang Pagdating sa Marle Hotel",
            "About the Hotel"           => "Tungkol sa Hotel",
            "Available Services"        => "Mga Available na Serbisyo",
            "Check-in & Check-out"      => "Pag-check-in at Pag-check-out",
            "Contact & Assistance"      => "Kontak at Tulong",
            "House Rules"               => "Mga Tuntunin sa Loob ng Hotel",
            "Marle Hotel & Restaurant offers a relaxing and affordable getaway for families, couples, and solo travellers. Located in a convenient area, we provide comfortable rooms, excellent dining, and personalized guest services." =>
                "Nag-aalok ang Marle Hotel & Restaurant ng nakakarelaks at abot-kayang bakasyon para sa mga pamilya, magkasintahan, at solo traveller. Nasa magandang lokasyon kami, at nagbibigay ng komportableng silid, masarap na pagkain, at personalisadong serbisyo.",
            "tab in the portal. All extensions are subject to room availability and approval." =>
                "tab sa portal. Ang lahat ng pagpapahaba ay nakasalalay sa available na silid at pag-apruba.",
            "tab or send us a message via chat." =>
                "tab o magpadala ng mensahe sa chat.",
            "Free Wi-Fi"                => "Libreng Wi-Fi",
            "Air Conditioning"          => "Aircon",
            "Parking"                   => "Paradahan",
            "Spa Services"              => "Mga Serbisyong Spa",
            "Keep noise levels low after 10:00 PM." =>
                "Panatilihing mahina ang ingay pagkatapos ng 10:00 ng gabi.",
        ],
    ],
];
