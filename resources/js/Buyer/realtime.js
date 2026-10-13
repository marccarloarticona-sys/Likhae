// Loaded only by authenticated Buyer pages. Keeping realtime out of the
// public marketplace bundle avoids creating a websocket and audio context for
// visitors who are only browsing the landing page.
import '../shared/echo.js';
import '../shared/messages.js';
import '../shared/notification-sounds.js';
