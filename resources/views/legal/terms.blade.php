@php($legal = config('dbelo.legal'))

<x-legal.shell title="Terms of Service" subtitle="The agreement between you and dbelo.">

    <p>These Terms govern your use of <strong>dbelo.com</strong> and everything on it. The service is operated by <strong>{{ $legal['entity'] }}</strong>. By creating an account or downloading a file, you agree to them.</p>

    <h2>1. What dbelo is</h2>
    <p>dbelo is a library of sound effects. You can listen to the entire catalogue for free. Downloading requires an account, and the number of downloads you get depends on your plan.</p>
    <p>Every sound carries a licence that says what you may do with it. Those licences are part of these Terms and are published at <a href="{{ route('legal.licenses') }}">dbelo.com/licenses</a>.</p>

    <h2>2. Your account</h2>
    <p>You need an account to download. You are responsible for keeping your credentials safe and for everything done through your account.</p>
    <p>One account is for one person. Sharing credentials so several people can download under a single subscription is a breach of these Terms and may result in suspension.</p>
    <p>You must be old enough to enter a contract where you live. If you are creating an account for a company, you confirm you are authorised to bind it.</p>

    <h2>3. Plans, payment and renewal</h2>
    <p>Plans and prices are published at <a href="{{ route('home') }}#pricing">dbelo.com</a>. Payments are processed by <strong>PayPal</strong>. We never see or store your card details.</p>
    <p>Paid plans renew automatically for the same period until you cancel. PayPal charges you on each renewal date and we email you a receipt.</p>

    <h3>Cancelling</h3>
    <p>You can cancel at any time from your account. <strong>Cancelling stops future charges but does not end the period you already paid for.</strong> You keep full access until the end of that period.</p>

    <h3>Refunds</h3>
    <p>Because downloaded files cannot be returned, payments are non-refundable once you have downloaded content in the current billing period. If you have not downloaded anything since the charge, write to <a href="mailto:{{ $legal['support_email'] }}">{{ $legal['support_email'] }}</a> within 14 days and we will refund it.</p>

    <h3>Price changes</h3>
    <p>We may change prices. Existing subscribers keep their price until the change is announced at least 30 days in advance by email, and it only applies from the next renewal.</p>

    <h2>4. What you may do with the sounds</h2>
    <p>Subject to the licence attached to each sound, you may use it in videos, films, games, podcasts, applications, advertising and other productions, including commercial ones.</p>

    <h3>What you may never do</h3>
    <ul>
        <li>Resell, redistribute or share the audio file on its own, modified or not.</li>
        <li>Publish it in another library, pack or marketplace.</li>
        <li>Register it, or a work consisting mainly of it, with a content identification system such as Content ID.</li>
        <li>Claim authorship of the recording.</li>
        <li>Use it to train machine learning or generative audio models without our written permission.</li>
        <li>Use it in a way that is unlawful, defamatory, or that depicts an identifiable person or brand in a false or damaging light.</li>
    </ul>

    <h3>After you cancel</h3>
    <p>Sounds you downloaded while subscribed stay licensed for the projects you had already used them in, under the licence terms in force at the time of download. You may not download new sounds, and you may not start new projects with premium sounds obtained under a plan you no longer hold.</p>

    <h2>5. Content uploaded by contributors</h2>
    <p>Part of the catalogue is uploaded by contributors. They keep the copyright of their recordings and grant us the right to distribute them. Contributors are bound by the <a href="{{ route('legal.contributor') }}">Contributor Agreement</a>.</p>
    <p>We review every upload before publishing, but we cannot guarantee that a contributor has told us the truth about the rights they hold. If you believe a sound infringes your rights, see section 9.</p>

    <h2>6. Availability</h2>
    <p>We aim to keep dbelo online continuously, but we do not promise uninterrupted service. Maintenance, failures and third-party outages happen.</p>
    <p>We may add, change or remove sounds from the catalogue at any time. A sound being removed does not affect the licence of a copy you already downloaded.</p>

    <h2>7. Suspension and termination</h2>
    <p>We may suspend or close an account that breaches these Terms, abuses the service, attempts to circumvent download limits, or is used for unlawful activity. Where the breach is not serious, we will warn you first and give you a chance to fix it.</p>
    <p>You may close your account at any time. Doing so does not entitle you to a refund of the current period.</p>

    <h2>8. Disclaimers and liability</h2>
    <p>dbelo is provided <strong>as is</strong>. We do not warrant that a particular sound is fit for your specific purpose.</p>
    <p>To the maximum extent permitted by law, our total liability arising from these Terms is limited to the amount you paid us in the twelve months before the event giving rise to the claim. We are not liable for lost profits, lost data or indirect damages.</p>
    <p>Nothing here limits liability that cannot be limited by law, including for fraud or gross negligence.</p>

    <h2>9. Copyright complaints</h2>
    <p>If you believe content on dbelo infringes your rights, file a complaint at <a href="{{ route('claims.create') }}">dbelo.com/copyright</a>. The form asks for the URL of the sound, proof of your rights and your contact details, and gives you a reference number you can quote if you need to follow up. You may also write to <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a> with the same information.</p>
    <p>We review every complaint by hand and, where the claim is credible, take the sound offline while we investigate. Its page stays up with a notice during that time, so links to it do not break. If we uphold the claim, the sound is removed from the catalogue permanently.</p>
    <p>One consequence cannot be undone, and we would rather say it than let you find out later: <strong>licences already granted are not revoked.</strong> Anyone who downloaded the sound before the complaint keeps the licence they were given for the work they had already made. Removal stops new downloads.</p>

    <h2>10. Changes to these Terms</h2>
    <p>We may update these Terms. Minor changes take effect on publication. Changes that materially affect your rights are announced by email at least 30 days in advance, and continuing to use the service after that means you accept them.</p>
    <p><strong>Licences already granted are not affected.</strong> Each download stores the licence text as it stood on that day, and that is the version that applies to that file forever.</p>

    <h2>11. Governing law</h2>
    <p>These Terms are governed by the laws of <strong>{{ $legal['jurisdiction'] }}</strong>, and disputes will be resolved by the courts of that jurisdiction, unless mandatory consumer law in your country of residence gives you the right to bring proceedings elsewhere.</p>

    <h2>12. Contact</h2>
    <p>{{ $legal['entity'] }}<br>{{ $legal['address'] }}<br><a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a></p>

</x-legal.shell>
