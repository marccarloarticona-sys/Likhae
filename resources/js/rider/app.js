import { initializeParcelScanners } from '../shared/parcel-scanner';
import '../shared/feedback.js';
import '../shared/echo';
import '../shared/messages';
import '../shared/notification-sounds';
import '../shared/rider-location';
import '../shared/workspace-shell.js';
import '../shared/payout-destination.js';

document.addEventListener('DOMContentLoaded', initializeParcelScanners);
