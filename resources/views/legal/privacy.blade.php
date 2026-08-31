@php($legal = config('dbelo.legal'))

<x-legal.shell title="Privacy Policy" subtitle="What we collect, why, and what you can ask us to do with it.">

    <p>This policy explains how <strong>{{ $legal['entity'] }}</strong> handles personal data on dbelo.com.</p>
    <p>The short version: we collect what the service needs to work and nothing else. We do not sell data, we do not run advertising networks, and we do not track you across other websites.</p>

    <h2>1. What we collect</h2>

    <h3>When you create an account</h3>
    <ul>
        <li>Name and email address</li>
        <li>Password, stored only as a cryptographic hash — we cannot read it</li>
        <li>Optionally: username, avatar, biography and website, if you fill them in</li>
    </ul>

    <h3>When you download a sound</h3>
    <ul>
        <li>Which sound, which file and when</li>
        <li>Your IP address and browser identification</li>
        <li>A copy of the licence text in force at that moment</li>
    </ul>
    <p>This record is not optional, and it exists for three concrete reasons: enforcing the daily download limit of your plan, giving you proof of the licence you obtained if anyone ever questions your right to use a sound, and detecting abuse such as automated mass downloading.</p>

    <h3>When you pay</h3>
    <p>Payments are processed by <strong>PayPal</strong>. Card and bank details are handled by PayPal and never reach our servers. We store the subscription identifier PayPal gives us, your plan, and the payment dates.</p>

    <h3>When you upload sounds</h3>
    <p>The audio file, its metadata, and anything you write about it. If you tell us a sound came from a third party, we store that origin information because we may need it to defend a rights claim.</p>

    <h3>Automatically</h3>
    <p>Our servers keep technical logs (IP address, requested URL, timestamp, errors) for security and debugging. They are deleted after 30 days.</p>

    <h2>2. Why we can use it</h2>
    <ul>
        <li><strong>To perform the contract:</strong> your account, downloads, subscription.</li>
        <li><strong>Legitimate interest:</strong> security, abuse prevention, keeping proof of licences granted.</li>
        <li><strong>Legal obligation:</strong> tax and accounting records of payments.</li>
        <li><strong>Consent:</strong> optional emails such as a newsletter, which you can withdraw at any time.</li>
    </ul>

    <h2>3. Who else sees it</h2>
    <p>We share the minimum necessary with the providers that make dbelo run:</p>
    <ul>
        <li><strong>PayPal</strong> — payment processing</li>
        <li><strong>Our email provider</strong> — sending account and notification emails</li>
        <li><strong>Our hosting and storage providers</strong> — running the site and storing audio files</li>
    </ul>
    <p>These providers process data on our instructions and cannot use it for their own purposes. Beyond that, we only disclose data when a law or a valid court order requires it.</p>
    <p><strong>We never sell personal data.</strong></p>

    <h2>4. Cookies</h2>
    <p>dbelo uses cookies only to keep you logged in and to protect forms against cross-site request forgery. They are strictly necessary for the service to work, so no consent banner is required.</p>
    <p>We do not use advertising or third-party tracking cookies. If we ever add analytics, this policy will be updated first and, where the law requires it, you will be asked for consent.</p>

    <h2>5. How long we keep it</h2>
    <ul>
        <li><strong>Account data:</strong> while your account exists.</li>
        <li><strong>Download records:</strong> kept even after the account closes, because they are the proof that a licence was granted. They are anonymised — the link to your identity is removed — after seven years.</li>
        <li><strong>Payment records:</strong> as long as tax law requires.</li>
        <li><strong>Server logs:</strong> 30 days.</li>
    </ul>

    <h2>6. Your rights</h2>
    <p>You can ask us to:</p>
    <ul>
        <li>Give you a copy of your data</li>
        <li>Correct anything wrong</li>
        <li>Delete your account and personal data</li>
        <li>Stop sending you optional emails</li>
        <li>Object to a processing based on legitimate interest</li>
    </ul>
    <p>Write to <a href="mailto:{{ $legal['privacy_email'] }}">{{ $legal['privacy_email'] }}</a>. We answer within 30 days.</p>
    <p>One honest limit: if you ask us to delete everything, we keep the download records in anonymised form. They no longer identify you, and they are what proves those licences were validly granted.</p>

    <h2>7. Security</h2>
    <p>Traffic is encrypted with HTTPS. Passwords are hashed with bcrypt. Master audio files are stored outside the public web root and are only served through a controller that checks your permissions. Access to production systems is restricted.</p>
    <p>No system is perfectly secure. If a breach affects your data, we will notify you and the competent authority as the law requires.</p>

    <h2>8. Children</h2>
    <p>dbelo is not aimed at children under 16. We do not knowingly collect their data. If you believe a child has created an account, tell us and we will delete it.</p>

    <h2>9. International transfers</h2>
    <p>Our providers may process data outside your country. When that happens we rely on the safeguards those providers offer, such as standard contractual clauses.</p>

    <h2>10. Changes</h2>
    <p>If we change this policy in a way that materially affects you, we will tell you by email before it takes effect.</p>

    <h2>11. Contact</h2>
    <p>{{ $legal['entity'] }}<br>{{ $legal['address'] }}<br><a href="mailto:{{ $legal['privacy_email'] }}">{{ $legal['privacy_email'] }}</a></p>

</x-legal.shell>
