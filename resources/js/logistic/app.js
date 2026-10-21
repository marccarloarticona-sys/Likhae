import { initializeParcelScanners } from '../shared/parcel-scanner';
import '../shared/feedback.js';
import '../address/forms.js';
import '../shared/echo.js';
import '../shared/messages.js';
import '../shared/notification-sounds.js';
import '../shared/workspace-shell.js';

document.addEventListener('DOMContentLoaded', initializeParcelScanners);
