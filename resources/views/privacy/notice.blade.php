<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Privacy Notice | {{ config('app.name', 'NU HRIS') }}</title>
    @include('partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#eceef1] text-slate-900 antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-5xl items-center px-4 py-6 sm:px-6 lg:px-8">
        <section class="flex max-h-[calc(100vh-3rem)] w-full flex-col overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-xl">
            <header class="shrink-0 border-b border-slate-200 bg-[#00386f] px-5 py-5 text-white sm:px-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-200">National University</p>
                        <h1 class="mt-2 text-2xl font-bold sm:text-3xl">National University HR Platform Privacy Notice</h1>
                        <p class="mt-2 text-sm text-blue-100">Please read and accept before continuing</p>
                    </div>
                    <span class="rounded-full border border-blue-200/40 px-3 py-1 text-xs font-semibold text-blue-100">Version {{ $version }}</span>
                </div>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-6 sm:px-8">
                <div class="space-y-7 text-sm leading-6 text-slate-700">
                    <section>
                        <h2 class="text-lg font-bold text-[#1f2b5d]">1. Introduction</h2>
                        <p class="mt-2">The National University HR Platform processes personal information necessary to provide HR-related services and operate the platform. This includes employee administration, authentication, degree information, timekeeping, leave and schedule management, work-from-home monitoring, notifications, reporting, and system security.</p>
                    </section>

                    <section>
                        <h2 class="text-lg font-bold text-[#1f2b5d]">2. Personal Data We Collect</h2>
                        <div class="mt-3 space-y-4">
                            <div><h3 class="font-semibold text-slate-900">Identity and employment information</h3><p>Employee ID, first name, last name, email address, department, position, employee type, faculty ranking, employment status, hire date, and official work schedule or time information.</p></div>
                            <div><h3 class="font-semibold text-slate-900">Account and authentication information</h3><p>Account name, email address, password hashes, role, email-verification status, password-reset information, session information, IP address, and user-agent information.</p></div>
                            <div><h3 class="font-semibold text-slate-900">Degree information</h3><p>Degree level, degree title, description, submitted document details, submission status, reviewer, review date, and review notes. Degree documents may contain additional information depending on what the employee submits.</p></div>
                            <div><h3 class="font-semibold text-slate-900">Attendance and DTR information</h3><p>Attendance dates, time-in, time-out, scheduled times, tardiness, undertime, overtime, schedule status, attendance status, and system-generated attendance notes or calculations.</p></div>
                            <div><h3 class="font-semibold text-slate-900">Leave information</h3><p>Leave type, leave dates, leave reason, days deducted, leave status, cutoff date, and remaining leave balances.</p></div>
                            <div><h3 class="font-semibold text-slate-900">Schedule information</h3><p>Submitted terms or academic years, working days, work indicators, scheduled times, submission status, review information, and review notes.</p></div>
                            <div><h3 class="font-semibold text-slate-900">WFH monitoring information</h3><p>WFH dates, time-in and time-out information, monitoring link, submission status, reviewer information, and review notes.</p></div>
                            <div><h3 class="font-semibold text-slate-900">Notifications, announcements, and system activity</h3><p>Announcements, notification recipients, read status, read timestamps, redirect links, account activity, audit actions, audit descriptions, timestamps, IP addresses, and selected audit metadata.</p></div>
                        </div>
                    </section>

                    <section>
                        <h2 class="text-lg font-bold text-[#1f2b5d]">3. Why We Process Personal Data</h2>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li>Employee administration and maintenance of HR records.</li>
                            <li>Account authentication, password management, session management, and security.</li>
                            <li>Degree submission and review.</li>
                            <li>Attendance and DTR management, calculations, and reporting.</li>
                            <li>Leave management, leave balances, eligibility rules, and attendance updates.</li>
                            <li>Schedule submission, review, approval, and attendance evaluation.</li>
                            <li>WFH monitoring, review, and attendance validation.</li>
                            <li>Operational notifications and announcements.</li>
                            <li>Reports, DTR exports, administrative oversight, auditing, and security monitoring.</li>
                            <li>Automated processing of imported records and system-generated attendance calculations.</li>
                        </ul>
                    </section>

                    <section>
                        <h2 class="text-lg font-bold text-[#1f2b5d]">4. Automated Processing and Document Extraction</h2>
                        <p class="mt-2">Biometric DTR PDF files are processed using <span class="font-semibold">smalot/pdfparser</span>. The system extracts text and uses recognized employee, date, and time values to create or update structured attendance records.</p>
                        <p class="mt-2">Excel files are processed using <span class="font-semibold">PhpSpreadsheet</span>. The system extracts relevant date, time, employee, leave, and status values from supported spreadsheets to create or update structured attendance or leave records.</p>
                        <p class="mt-2">The system can automatically calculate attendance-related information such as tardiness, undertime, overtime, schedule status, and attendance status. HR/Admin workflows remain involved in reviewing and approving relevant records.</p>
                    </section>

                    <section>
                        <h2 class="text-lg font-bold text-[#1f2b5d]">5. Role-Based Access</h2>
                        <div class="mt-3 space-y-3">
                            <p><span class="font-semibold text-slate-900">Employee:</span> Primarily accesses their own profile and HR records. Employees can manage their own degree information, schedules, WFH submissions, account information, and notifications, and view their own HR-related information available through the employee module.</p>
                            <p><span class="font-semibold text-slate-900">HR:</span> Can access and manage employee HR records needed for HR operations, including employee records, degree information, DTRs, leave information, schedules, WFH submissions, announcements, and related review workflows according to the implemented permissions.</p>
                            <p><span class="font-semibold text-slate-900">Admin:</span> Has broader administrative access, including employee management, degree information and document deletion, DTR and WFH administration, schedule administration, role management, configuration, reports, and audit logs according to the implemented permissions.</p>
                        </div>
                    </section>

                    <section>
                        <h2 class="text-lg font-bold text-[#1f2b5d]">6. Third-Party and External Services</h2>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li><span class="font-semibold">Supabase PostgreSQL:</span> The configured application database stores HR platform records.</li>
                            <li><span class="font-semibold">Supabase Storage:</span> Submitted degree documents are stored in the configured storage bucket and are accessed through temporary signed URLs.</li>
                            <li><span class="font-semibold">Supabase Auth Admin API:</span> User email, role-related metadata, and password synchronization data may be sent for account provisioning, password updates, or account deletion.</li>
                            <li><span class="font-semibold">Gmail SMTP:</span> The configured mail service is used for application email workflows such as password resets and email verification.</li>
                            <li><span class="font-semibold">Local processing libraries:</span> smalot/pdfparser, PhpSpreadsheet, and DomPDF process imported or generated documents within the application workflow.</li>
                        </ul>
                        <p class="mt-3">Hostinger is not identified in the code as receiving application data. It appears only in deployment-related comments.</p>
                    </section>

                    <section>
                        <h2 class="text-lg font-bold text-[#1f2b5d]">7. Data Retention and Deletion</h2>
                        <p class="mt-2">The platform provides deletion or clearing functions for certain employee records, degree records and documents, attendance/DTR records, leave records, WFH records, schedule records, notifications, announcements, and other administrative records where applicable.</p>
                        <p class="mt-2">Specific retention periods are not currently defined in the application code. Personal data is retained only for as long as necessary for the applicable HR, operational, legal, or institutional requirements. Specific retention periods may be governed by applicable National University policies and records-management requirements. This statement does not establish or claim a specific National University retention period.</p>
                    </section>

                    <section>
                        <h2 class="text-lg font-bold text-[#1f2b5d]">8. Security Measures</h2>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li>Session-based authentication and role-based middleware.</li>
                            <li>Password hashing, password-reset expiration, and login rate limiting.</li>
                            <li>Session regeneration after login and session invalidation on logout.</li>
                            <li>CSRF protection and input/file validation.</li>
                            <li>Ownership checks for employee degree documents and WFH submission access.</li>
                            <li>Temporary signed Supabase Storage URLs for file viewing.</li>
                            <li>Database SSL requirement in the configured environment.</li>
                            <li>Audit logging and filtering of passwords, tokens, keys, secrets, and uploaded file values from audit metadata.</li>
                        </ul>
                        <p class="mt-3">The application does not claim end-to-end encryption, multi-factor authentication, database-column encryption, complete production HTTPS enforcement, or database row-level security based on the current code.</p>
                    </section>

                    <section>
                        <h2 class="text-lg font-bold text-[#1f2b5d]">9. Data Sources</h2>
                        <p class="mt-2">Personal data may come from employees, HR personnel, administrators, submitted degree documents, imported biometric DTR PDFs, imported leave Excel files, WFH submissions and monitoring links, schedule submissions, authentication and session activity, and system-generated calculations.</p>
                    </section>

                    <section>
                        <h2 class="text-lg font-bold text-[#1f2b5d]">10. Data Subject Rights</h2>
                        <p class="mt-2">Subject to applicable law, including the Philippine Data Privacy Act of 2012 (Republic Act No. 10173), data subjects may have rights such as the right to be informed, right to access, right to correct, right to object, right to suspend or withdraw consent where applicable, right to erasure or blocking where applicable, right to data portability where applicable, and right to file a complaint.</p>
                        <p class="mt-2">The applicable rights and legal bases may depend on the specific processing activity. Processing in this HR platform is not represented as being based solely on consent.</p>
                    </section>

                    <section>
                        <h2 class="text-lg font-bold text-[#1f2b5d]">11. Privacy Contact</h2>
                        <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-amber-900">[Official NU Data Privacy Office contact information to be provided by National University]</p>
                        <p class="mt-2">No official DPO or privacy contact has been configured in this capstone implementation.</p>
                    </section>
                </div>
            </div>

            <footer class="shrink-0 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-8">
                <form method="POST" action="{{ route('privacy.notice.acknowledge') }}">
                    @csrf
                    <label class="flex cursor-pointer items-start gap-3 text-sm font-semibold text-slate-800">
                        <input id="privacy-acknowledged" name="acknowledged" value="1" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300 text-[#00386f] focus:ring-[#00386f]">
                        <span>I have read and understand the National University HR Platform Privacy Notice.</span>
                    </label>
                    @error('acknowledged')
                        <p class="mt-2 text-xs text-red-600">Please confirm that you have read and understand the Privacy Notice.</p>
                    @enderror
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs text-slate-500">You may log out without accepting this notice.</p>
                        <div class="flex items-center gap-2">
                            <button type="submit" form="logout-form" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Log out</button>
                            <button id="privacy-continue" type="submit" disabled class="rounded-lg bg-[#00386f] px-5 py-2 text-sm font-semibold text-white opacity-50 transition enabled:hover:bg-[#002d5a] enabled:opacity-100 disabled:cursor-not-allowed">Continue</button>
                        </div>
                    </div>
                </form>
                <form id="logout-form" method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>
            </footer>
        </section>
    </main>

    <script @cspNonce>
        const acknowledgement = document.getElementById('privacy-acknowledged');
        const continueButton = document.getElementById('privacy-continue');
        acknowledgement.addEventListener('change', () => {
            continueButton.disabled = !acknowledgement.checked;
        });
    </script>
</body>
</html>
