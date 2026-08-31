<?php

namespace App\Http\Controllers;

use App\Models\Subscriber;
use Illuminate\Http\Response;

/**
 * Gmail and Yahoo's one-click unsubscribe.
 *
 * They POST straight to the List-Unsubscribe URL and never render anything —
 * the reader just sees the button disappear. It arrives with no session and
 * no CSRF token, which is why this route is excluded from that check in
 * bootstrap/app.php: the token in the URL is the only credential, and it is
 * random enough to be one.
 *
 * Worth honouring properly. A working button here is what keeps people from
 * pressing "report spam" instead — and spam reports are what make the
 * password-reset emails stop arriving too.
 */
class OneClickUnsubscribeController extends Controller
{
    public function __invoke(string $token): Response
    {
        Subscriber::where('token', $token)->first()?->unsubscribe();

        // The sending provider wants a 200 and nothing else.
        return response('', 200);
    }
}
