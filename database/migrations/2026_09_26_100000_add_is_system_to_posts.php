<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The legal pages become editable, and become impossible to lose.
 *
 * ── WHAT THEY WERE ───────────────────────────────────────────────────────
 *
 * Blade files under resources/views/legal, served by LegalController. Good
 * for a developer, useless for the person who has to change "30 days" to
 * "14 days" on a Sunday — and they had been silently 500ing since the day
 * they were written, because the shell they all include was saved in the
 * wrong folder and nobody had opened them.
 *
 * ── WHY is_system ────────────────────────────────────────────────────────
 *
 * Because "editable" and "deletable" are not the same permission, and the
 * CMS only had the one. The footer links these pages, the sitemap lists
 * them, the shell at the bottom of each one points at the other two, and
 * the Terms link to the Contributor Agreement from inside their own text.
 * A page that can be deleted from a list with a trash icon is a page that
 * will be, and the site would answer 404 on its own terms of service.
 *
 * The flag locks two things and nothing else: the row cannot be deleted and
 * the slug cannot change. Title, body, excerpt and SEO stay as editable as
 * any other page — which was the whole point.
 *
 * ── WHY licenses IS NOT HERE ─────────────────────────────────────────────
 *
 * /licenses is not prose. It is a loop over the licenses table printing
 * each licence's name, version, summary and permissions, all maintained in
 * Admin → Licenses. Seeding it as text would freeze today's rows into a
 * document, and from tomorrow the page and the licence actually attached to
 * each sound would drift apart with nothing to notice it. It stays a view.
 *
 * ── WHY THE BODIES ARE HTML AND NOT MARKDOWN ─────────────────────────────
 *
 * Post::render() runs CommonMark with html_input => allow, so the existing
 * markup works untouched. Converting three legal documents into markdown by
 * hand would have risked changing what they say in order to change how they
 * are stored, and nothing about the storage needed that. Markdown typed
 * into the editor later works alongside it.
 *
 * The company details are the one substitution: `{{ $legal['entity'] }}`
 * became `{entity}`, filled by App\Support\Legal at render. See that class
 * for why they are not written out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('in_footer');
        });

        foreach ($this->pages() as $page) {
            $existing = DB::table('posts')
                ->where('type', 'page')
                ->where('slug', $page['slug'])
                ->first();

            /*
             * Never overwrite. If a page with this slug already exists the
             * operator made it, and their words win — all this migration
             * does then is mark it as one the site needs. A migration that
             * replaces content is a migration that eats somebody's evening.
             */
            if ($existing) {
                DB::table('posts')->where('id', $existing->id)->update(['is_system' => true]);

                continue;
            }

            DB::table('posts')->insert([
                'type' => 'page',
                'slug' => $page['slug'],
                'title' => $page['title'],
                'excerpt' => $page['excerpt'],
                'body' => $page['body'],
                'meta_title' => $page['meta_title'],
                'meta_description' => $page['meta_description'],
                'status' => 'published',
                'published_at' => now(),
                // Not in the footer: the Legal column already links all four
                // by route name, and a second copy under Resources would be
                // the same page listed twice in one footer.
                'in_footer' => false,
                'is_system' => true,
                'sort_order' => $page['sort_order'],
                'reading_minutes' => max(1, (int) round(str_word_count(strip_tags($page['body'])) / 200)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('posts')
            ->where('type', 'page')
            ->where('is_system', true)
            ->whereIn('slug', ['terms', 'privacy', 'contributors'])
            ->delete();

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function pages(): array
    {
        return [
            [
                'slug' => 'terms',
                'title' => "Terms of Service",
                'excerpt' => "The agreement between you and dbelo: accounts, payments, what the licences cover, and what happens if something goes wrong.",
                'meta_title' => "Terms of use",
                'meta_description' => "What you agree to when you use dbelo: accounts, downloads, what the licences cover, and what happens if a sound turns out not to be ours to give.",
                'sort_order' => 10,
                'body' => <<<'LEGAL'
<p>These Terms govern your use of <strong>dbelo.com</strong> and everything on it. The service is operated by <strong>{entity}</strong>. By creating an account or downloading a file, you agree to them.</p>

    <h2>1. What dbelo is</h2>
    <p>dbelo is a library of sound effects. You can listen to the entire catalogue for free. Downloading requires an account, and the number of downloads you get depends on your plan.</p>
    <p>Every sound carries a licence that says what you may do with it. Those licences are part of these Terms and are published at <a href="/licenses">dbelo.com/licenses</a>.</p>

    <h2>2. Your account</h2>
    <p>You need an account to download. You are responsible for keeping your credentials safe and for everything done through your account.</p>
    <p>One account is for one person. Sharing credentials so several people can download under a single subscription is a breach of these Terms and may result in suspension.</p>
    <p>You must be old enough to enter a contract where you live. If you are creating an account for a company, you confirm you are authorised to bind it.</p>

    <h2>3. Plans, payment and renewal</h2>
    <p>Plans and prices are published at <a href="/#pricing">dbelo.com</a>. Payments are processed by <strong>PayPal</strong>. We never see or store your card details.</p>
    <p>Paid plans renew automatically for the same period until you cancel. PayPal charges you on each renewal date and we email you a receipt.</p>

    <h3>Cancelling</h3>
    <p>You can cancel at any time from your account. <strong>Cancelling stops future charges but does not end the period you already paid for.</strong> You keep full access until the end of that period.</p>

    <h3>Refunds</h3>
    <p>Because downloaded files cannot be returned, payments are non-refundable once you have downloaded content in the current billing period. If you have not downloaded anything since the charge, write to <a href="mailto:{support_email}">{support_email}</a> within 14 days and we will refund it.</p>

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
    <p>Part of the catalogue is uploaded by contributors. They keep the copyright of their recordings and grant us the right to distribute them. Contributors are bound by the <a href="/contributors">Contributor Agreement</a>.</p>
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
    <p>If you believe content on dbelo infringes your rights, file a complaint at <a href="/copyright">dbelo.com/copyright</a>. The form asks for the URL of the sound, proof of your rights and your contact details, and gives you a reference number you can quote if you need to follow up. You may also write to <a href="mailto:{email}">{email}</a> with the same information.</p>
    <p>We review every complaint by hand and, where the claim is credible, take the sound offline while we investigate. Its page stays up with a notice during that time, so links to it do not break. If we uphold the claim, the sound is removed from the catalogue permanently.</p>
    <p>One consequence cannot be undone, and we would rather say it than let you find out later: <strong>licences already granted are not revoked.</strong> Anyone who downloaded the sound before the complaint keeps the licence they were given for the work they had already made. Removal stops new downloads.</p>

    <h2>10. Changes to these Terms</h2>
    <p>We may update these Terms. Minor changes take effect on publication. Changes that materially affect your rights are announced by email at least 30 days in advance, and continuing to use the service after that means you accept them.</p>
    <p><strong>Licences already granted are not affected.</strong> Each download stores the licence text as it stood on that day, and that is the version that applies to that file forever.</p>

    <h2>11. Governing law</h2>
    <p>These Terms are governed by the laws of <strong>{jurisdiction}</strong>, and disputes will be resolved by the courts of that jurisdiction, unless mandatory consumer law in your country of residence gives you the right to bring proceedings elsewhere.</p>

    <h2>12. Contact</h2>
    <p>{entity}<br>{address}<br><a href="mailto:{email}">{email}</a></p>
LEGAL,
            ],
            [
                'slug' => 'privacy',
                'title' => "Privacy Policy",
                'excerpt' => "What dbelo stores about you, why, for how long, and how to have it deleted.",
                'meta_title' => "Privacy policy",
                'meta_description' => "What dbelo stores about you, why, for how long, and how to have it deleted. Written to be read rather than to be survived.",
                'sort_order' => 11,
                'body' => <<<'LEGAL'
<p>This policy explains how <strong>{entity}</strong> handles personal data on dbelo.com.</p>
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
    <p>Write to <a href="mailto:{privacy_email}">{privacy_email}</a>. We answer within 30 days.</p>
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
    <p>{entity}<br>{address}<br><a href="mailto:{privacy_email}">{privacy_email}</a></p>
LEGAL,
            ],
            [
                'slug' => 'contributors',
                'title' => "Contributor Agreement",
                'excerpt' => "The terms for uploading your own recordings to dbelo: what you keep, what you grant, and how to take a sound back down.",
                'meta_title' => "Contributor agreement",
                'meta_description' => "The terms for uploading your own recordings to dbelo: what you keep, what you grant, and how to take a sound back down.",
                'sort_order' => 12,
                'body' => <<<'LEGAL'
<p>This agreement applies when you upload audio to dbelo. It exists to make one thing unambiguous: <strong>you keep the copyright of what you record.</strong> What you give us is permission to distribute it.</p>

    <h2>1. You keep your rights</h2>
    <p>Uploading a sound does not transfer its copyright. It remains yours, and you can keep selling or licensing it anywhere else you like. dbelo is not an exclusive arrangement unless we agree otherwise in writing.</p>

    <h2>2. What you grant us</h2>
    <p>You grant {entity} a worldwide, non-exclusive licence to:</p>
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
    <p><a href="mailto:{support_email}">{support_email}</a></p>
LEGAL,
            ],
        ];
    }
};
