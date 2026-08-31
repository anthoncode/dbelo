@php($legal = config('dbelo.legal'))

<x-legal.shell title="Contributor Agreement" subtitle="What happens when you upload a sound to dbelo.">

    <p>This agreement applies when you upload audio to dbelo. It exists to make one thing unambiguous: <strong>you keep the copyright of what you record.</strong> What you give us is permission to distribute it.</p>

    <h2>1. You keep your rights</h2>
    <p>Uploading a sound does not transfer its copyright. It remains yours, and you can keep selling or licensing it anywhere else you like. dbelo is not an exclusive arrangement unless we agree otherwise in writing.</p>

    <h2>2. What you grant us</h2>
    <p>You grant {{ $legal['entity'] }} a worldwide, non-exclusive licence to:</p>
    <ul>
        <li>Store, convert and process your file — we generate MP3 previews and download versions, and compute a waveform</li>
        <li>Publish it in the dbelo catalogue and make it available for listening and download</li>
        <li>Sublicense it to dbelo users under the licence you chose when uploading</li>
        <li>Use its title, waveform and a short excerpt to promote dbelo</li>
    </ul>
    <p>This licence lasts as long as the sound is in the catalogue, plus whatever is needed to honour licences already granted to users.</p>

    <h2>3. What you promise us</h2>
    <p>By uploading, you confirm that:</p>
    <ul>
        <li>You recorded or created the sound, or you hold the rights to license it</li>
        <li>It does not infringe anyone's copyright, trademark or other rights</li>
        <li>If it contains an identifiable voice, you have that person's permission</li>
        <li>If it was recorded on private property or at an event that restricts recording, you had permission</li>
        <li>It does not contain material sampled from commercial recordings</li>
    </ul>
    <p>This is the most important section of the agreement. If a claim reaches us over a sound you uploaded, you are responsible for it, and you agree to cover the reasonable costs of defending it.</p>

    <h2>4. Sounds from third parties</h2>
    <p>If you upload something you did not record — a Creative Commons recording, a public domain file, material you licensed — you must say so when uploading and fill in the origin, the original author and the source URL.</p>
    <p>This is not bureaucracy. Licences such as CC BY require attribution, and without that information we cannot comply on your behalf. Uploading third-party material without declaring it is a breach of this agreement.</p>

    <h2>5. Review</h2>
    <p>Everything is reviewed before publication. We may reject a sound for technical quality, for duplicating existing catalogue, for incomplete metadata, or for doubts about its rights. Rejections always come with a reason.</p>
    <p>We may edit titles, categories, tags and descriptions to keep the catalogue consistent. We will not edit your audio without telling you.</p>

    <h2>6. Removal</h2>
    <p>You can ask us to remove any of your sounds at any time, and we will take them offline.</p>
    <p>One limitation applies and it cannot be avoided: <strong>users who already downloaded it keep their licence.</strong> Someone who put your sound in a film last year does not lose the right to keep distributing that film. Removal stops new downloads, it does not revoke licences already granted.</p>

    <h2>7. Payment</h2>
    <p>dbelo does not currently share revenue with contributors. Uploads are voluntary and credited to you on your public profile.</p>
    <p>If we introduce a revenue share, we will announce it and this agreement will be updated. Nothing here obliges you to keep contributing.</p>

    <h2>8. Conduct</h2>
    <p>Uploading material you have no rights to, mass-uploading duplicates, or manipulating metadata to game the search results are grounds for removing your contributor status.</p>

    <h2>9. Changes</h2>
    <p>If we change this agreement, we will notify contributors by email at least 30 days in advance. Sounds already published stay under the version you accepted when you uploaded them.</p>

    <h2>10. Contact</h2>
    <p><a href="mailto:{{ $legal['support_email'] }}">{{ $legal['support_email'] }}</a></p>

</x-legal.shell>
