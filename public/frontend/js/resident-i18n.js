// English / Filipino switch for the resident portal.
//
// The page is written in English. In Filipino mode, any text on the page (and placeholder,
// aria-label and title) that exactly matches an entry below is shown in Filipino, including
// text the portal adds later; sentences with numbers or names go through window.t().
// Messages typed by barangay staff and residents' own report text are never changed.
(function () {
  const FIL = {
    // Menu and page headings
    'Open menu': 'Buksan ang menu',
    'Close menu': 'Isara ang menu',
    'Unread notifications': 'Mga hindi pa nababasang abiso',
    'Resident navigation': 'Menu ng residente',
    'Menu': 'Menu',
    'Report an Issue': 'Mag-ulat ng Problema',
    'My Reports': 'Aking mga Ulat',
    'Notifications': 'Mga Abiso',
    'Account': 'Account',
    'Language': 'Wika',

    // Report an issue
    'Submit an incident and receive an automated tracking ID.': 'Magpadala ng ulat at makatanggap ng tracking ID.',
    'How your report is handled': 'Paano inaasikaso ang iyong ulat',
    'Submit:': 'Ipadala:',
    'Fill out the incident details.': 'Ilagay ang mga detalye ng problema.',
    'Validation:': 'Pagsusuri:',
    'Officials review and confirm report accuracy.': 'Sinusuri at kinukumpirma ng mga opisyal ang ulat.',
    'Dispatch:': 'Pagpapadala:',
    'Field team/purok leader is deployed.': 'Ipinapadala ang team o ang purok leader.',
    'Resolution:': 'Pag-aayos:',
    'Issue resolved and closed with timestamp.': 'Naaayos ang problema at isinasara na may petsa at oras.',
    'Issue Category': 'Uri ng Problema',
    'Choose category': 'Pumili ng uri',
    'Village / Phase': 'Village / Phase',
    'Choose village / phase': 'Pumili ng village / phase',
    'Street': 'Kalye',
    'Choose village / phase first': 'Pumili muna ng village / phase',
    'Choose street': 'Pumili ng kalye',
    'Area-wide': 'Buong lugar',
    'Detailed Description': 'Detalyadong Paglalarawan',
    'Describe the incident in 1 to 500 characters.': 'Ilarawan ang problema (1 hanggang 500 titik).',
    'Include enough detail for a response team to understand the issue (up to 500 characters).': 'Maglagay ng sapat na detalye para maintindihan ng team ang problema (hanggang 500 titik).',
    'Specific Landmark / Marker': 'Palatandaan / Landmark',
    'e.g. Near Barangay Hall': 'hal. Malapit sa Barangay Hall',
    'Enter a specific landmark or location marker': 'Maglagay ng palatandaan o landmark',
    'Optional: the map pin marks the spot': 'Opsyonal: ang pin sa mapa ang nagtuturo ng lugar',
    'Tag Location': 'I-pin sa Mapa',
    'Choose the incident location': 'Piliin ang lugar ng problema',
    'Click the map to place a marker, or drag it to adjust the location.': 'I-click ang mapa para maglagay ng pin, o hilahin ito para iayos ang lugar.',
    'Close map': 'Isara ang mapa',
    'Close': 'Isara',
    'Map location picker': 'Pagpili ng lugar sa mapa',
    'Use my current location': 'Gamitin ang kinaroroonan ko',
    'Use this location': 'Gamitin ang lugar na ito',
    'Loading the map...': 'Nilo-load ang mapa...',
    'Map centered near San Jose del Monte. Click the map to place a marker, or drag it to adjust the location.': 'Nakatutok ang mapa malapit sa San Jose del Monte. I-click ang mapa para maglagay ng pin, o hilahin ito para iayos ang lugar.',
    'Click the map to select the incident location before continuing.': 'I-click muna ang mapa para piliin ang lugar ng problema.',
    'Geolocation is not supported by your browser. Click the map to place the marker manually.': 'Hindi kaya ng browser mo na hanapin ang lokasyon. I-click ang mapa para maglagay ng pin.',
    'Acquiring your current location...': 'Hinahanap ang kinaroroonan mo...',
    'Unable to fetch your location. Please allow location access or click the map to place the marker manually.': 'Hindi makuha ang lokasyon mo. Payagan ang location access o i-click ang mapa para maglagay ng pin.',
    'The map could not be loaded. Check your internet connection and try again.': 'Hindi ma-load ang mapa. Tingnan ang internet at subukan muli.',
    'Photos (up to 5)': 'Mga larawan (hanggang 5)',
    'Video (optional, 1)': 'Video (opsyonal, 1)',
    'Each photo or video up to 10 MB; 25 MB in total.': 'Hanggang 10 MB bawat larawan o video; 25 MB lahat-lahat.',
    'Selected photos and video': 'Mga napiling larawan at video',
    'Each photo must be 10 MB or less.': 'Hanggang 10 MB lang bawat larawan.',
    'The video must be 10 MB or less.': 'Hanggang 10 MB lang ang video.',
    'Photos and video together must be 25 MB or less.': 'Hanggang 25 MB lang ang lahat ng larawan at video.',
    'Submit Issue & Generate Tracking ID': 'Ipadala ang Ulat at Kunin ang Tracking ID',
    'Checking which category fits…': 'Tinitingnan kung anong uri ang angkop…',
    'AI suggestion': 'Mungkahi ng AI',
    'Suggested from keywords': 'Mungkahi batay sa mga salita',
    'Use this': 'Gamitin ito',
    'Select an issue category.': 'Pumili ng uri ng problema.',
    'Choose the village or phase.': 'Pumili ng village o phase.',
    'Select a street for the chosen village.': 'Pumili ng kalye sa napiling village.',
    'Add a landmark when the selected area has no street list.': 'Maglagay ng landmark kapag walang listahan ng kalye ang napiling lugar.',
    'The incident description must be between 1 and 500 characters.': 'Ang paglalarawan ay dapat 1 hanggang 500 titik.',
    'Report Submitted!': 'Naipadala ang Ulat!',
    'Your tracking ID is': 'Ang iyong tracking ID ay',

    // My reports
    'Follow open issues or review reports that have been closed.': 'Subaybayan ang mga bukas na ulat o tingnan ang mga naisara na.',
    'Filter my reports': 'Salain ang aking mga ulat',
    'Current': 'Kasalukuyan',
    'Past Reports': 'Mga Nakaraang Ulat',
    'All': 'Lahat',
    'Past': 'Nakaraan',
    'Unavailable': 'Hindi available',
    'Report pagination': 'Mga pahina ng ulat',
    'Previous': 'Nakaraan',
    'Next': 'Susunod',
    'You have no open reports right now. Select Past Reports to review completed reports.': 'Wala kang bukas na ulat ngayon. Piliin ang Mga Nakaraang Ulat para makita ang mga natapos na.',
    'Resolved and rejected reports will appear here.': 'Dito lalabas ang mga naayos at tinanggihang ulat.',
    'Submit an issue above and it will appear here with its tracking ID and status.': 'Magpadala ng ulat at lalabas ito rito kasama ang tracking ID at status.',
    'Submitted': 'Naisumite',
    'Validated': 'Nasuri',
    'Dispatched': 'Ipinadala ang team',
    'Resolved': 'Naayos',
    'Rejected': 'Tinanggihan',
    'Withdrawn': 'Binawi',
    'Report Rejected': 'Tinanggihan ang Ulat',
    'Withdrawn by you': 'Binawi mo',
    'Critical': 'Kritikal',
    'High': 'Mataas',
    'Normal': 'Karaniwan',
    'Latest Status:': 'Pinakabagong Status:',
    'Report received and queued for administrative validation.': 'Natanggap ang ulat at naghihintay ng pagsusuri.',
    'Report submitted.': 'Naisumite ang ulat.',
    'Your report has been submitted.': 'Naisumite na ang iyong ulat.',
    'The resident confirmed that the issue is fixed.': 'Kinumpirma ng residente na naayos na ang problema.',
    'Status history': 'Kasaysayan ng status',
    'Edit': 'I-edit',
    'Withdraw': 'Bawiin',
    'The barangay marked this report resolved. Is the problem fixed?': 'Minarkahan ng barangay na naayos na ang ulat na ito. Naayos na ba talaga ang problema?',
    "Yes, it's fixed": 'Oo, naayos na',
    'No, still a problem': 'Hindi, may problema pa',
    'Problem came back?': 'Bumalik ang problema?',
    'Closed as fixed (no reply within 7 days).': 'Isinara bilang naayos (walang sagot sa loob ng 7 araw).',
    'You reopened this report. The barangay will send the team again.': 'Binuksan mo ulit ang ulat na ito. Ipapadala ulit ng barangay ang team.',
    'Still a problem? This report was already reopened twice, so please contact the barangay office.': 'May problema pa? Dalawang beses nang binuksan ulit ang ulat na ito, kaya makipag-ugnayan sa barangay office.',
    'Confirm that the problem is fixed? This closes the report.': 'Kumpirmahin na naayos na ang problema? Isasara nito ang ulat.',
    'Thank you for confirming. The report is now closed.': 'Salamat sa pagkumpirma. Isinara na ang ulat.',
    'What is wrong? The report goes back to the barangay so they can send the team again.': 'Ano ang mali? Ibabalik ang ulat sa barangay para maipadala ulit ang team.',
    'For example: the pothole was only partly filled': 'Halimbawa: bahagya lang natambakan ang lubak',
    'Reopen report': 'Buksan ulit ang ulat',
    'The problem is still there.': 'Nandiyan pa rin ang problema.',
    'It was only partly fixed.': 'Bahagya lang naayos.',
    'The problem came back.': 'Bumalik ang problema.',
    'Your report was reopened and sent back to the barangay.': 'Binuksan ulit ang ulat at ibinalik sa barangay.',
    'Withdraw this report? The barangay will stop working on it. You can tell them why (optional).': 'Bawiin ang ulat na ito? Ititigil ito ng barangay. Maaari mong sabihin kung bakit (opsyonal).',
    'For example: the problem was fixed': 'Halimbawa: naayos na ang problema',
    'Withdraw report': 'Bawiin ang ulat',
    'Your report has been withdrawn.': 'Nabawi na ang iyong ulat.',
    'Edit report': 'I-edit ang ulat',
    'You can edit a report until the barangay starts reviewing it.': 'Maaari mong i-edit ang ulat hangga\'t hindi pa ito sinusuri ng barangay.',
    'Issue category': 'Uri ng problema',
    'Description': 'Paglalarawan',
    'Landmark (optional)': 'Landmark (opsyonal)',
    'Save changes': 'I-save',
    'Please describe the issue.': 'Pakilarawan ang problema.',
    'Your report has been updated.': 'Na-update na ang iyong ulat.',

    // Notifications
    'Updates from the barangay about your reports.': 'Mga update mula sa barangay tungkol sa iyong mga ulat.',
    'Filter notifications': 'Salain ang mga abiso',
    'No new updates available yet.': 'Wala pang bagong update.',
    'No updates about reports that are still in progress.': 'Walang update tungkol sa mga ulat na inaasikaso pa.',
    'No updates about resolved or closed reports.': 'Walang update tungkol sa mga naayos o naisarang ulat.',
    'No updates available yet.': 'Wala pang update.',
    'Read': 'Nabasa na',
    'Mark as read': 'Markahang nabasa',

    // Account
    'Your sign-in details and password.': 'Ang iyong mga detalye sa pag-sign in at password.',
    'Profile': 'Profile',
    'Full name': 'Buong pangalan',
    'Username': 'Username',
    'Email': 'Email',
    'Role': 'Tungkulin',
    'Resident': 'Residente',
    'To change your name or email, contact the barangay office.': 'Para palitan ang pangalan o email, makipag-ugnayan sa barangay office.',
    'Email updates': 'Mga update sa email',
    'Email me when the barangay updates one of my reports (reviewed, team sent, resolved, or not accepted)': 'I-email ako kapag may update ang barangay sa aking ulat (nasuri, naipadala ang team, naayos, o hindi tinanggap)',
    'Emails turned on.': 'Naka-on na ang email.',
    'Emails turned off. Updates still appear in Notifications.': 'Naka-off na ang email. Makikita pa rin ang mga update sa Mga Abiso.',
    'Change password': 'Palitan ang password',
    'Current password': 'Kasalukuyang password',
    'New password': 'Bagong password',
    'At least 12 characters, with uppercase and lowercase letters, a number, and a symbol.': 'Hindi bababa sa 12 titik, may malaki at maliit na titik, numero, at simbolo.',
    'Confirm new password': 'Kumpirmahin ang bagong password',
    'For your security, we will email a 6-digit code to confirm the change. Your other signed-in devices will be signed out afterwards.': 'Para sa iyong seguridad, magpapadala kami ng 6-digit na code sa email para kumpirmahin ang pagbabago. Masa-sign out pagkatapos ang iba mo pang device.',
    'Send verification code': 'Ipadala ang code',
    'Verification code': 'Verification code',
    'Confirm change': 'Kumpirmahin',
    'Send a new code': 'Magpadala ng bagong code',
    'Sign out': 'Mag-sign out',
    'End your session on this device. You will need your password to sign in again.': 'Tapusin ang session sa device na ito. Kakailanganin mo ang password para mag-sign in ulit.',
    'Log out': 'Mag-log out',
    'Are you sure you want to log out?': 'Sigurado ka bang gusto mong mag-log out?',
    'Show password': 'Ipakita ang password',
    'Hide password': 'Itago ang password',

    // Dialog buttons
    'Cancel': 'Kanselahin',
    'Confirm': 'Kumpirmahin',
    'OK': 'OK',
    'Please enter a reason.': 'Pakilagay ang dahilan.',

    // Sentences with values (used through window.t)
    'Welcome, {name}': 'Maligayang pagdating, {name}',
    '{n} current report': '{n} kasalukuyang ulat',
    '{n} current reports': '{n} kasalukuyang ulat',
    '{n} past report': '{n} nakaraang ulat',
    '{n} past reports': '{n} nakaraang ulat',
    '{n} report': '{n} ulat',
    '{n} reports': '{n} ulat',
    'Page {page} of {last} · {total} reports': 'Pahina {page} ng {last} · {total} ulat',
    '{priority} Priority': 'Priyoridad: {priority}',
    'Please answer by {date}. With no answer, the report closes as fixed.': 'Pakisagot bago ang {date}. Kung walang sagot, isasara ang ulat bilang naayos.',
    'You confirmed this is fixed on {date}.': 'Kinumpirma mong naayos ito noong {date}.',
    'You can reopen this report until {date}.': 'Maaari mong buksan ulit ang ulat hanggang {date}.',
    'Choose at most {n} photos.': 'Hanggang {n} larawan lang ang maaari.',
    'Selected location: {lat}, {lng}. Click or drag the marker to adjust it.': 'Napiling lugar: {lat}, {lng}. I-click o hilahin ang pin para iayos.',
    'Map location attached: {lat}, {lng}': 'Naka-pin na lugar: {lat}, {lng}',
    'Notifications unavailable: {message}': 'Hindi makuha ang mga abiso: {message}'
  };

  const ATTRIBUTES = ['placeholder', 'aria-label', 'title'];
  const originalText = new WeakMap();   // text node -> its English text
  const shownText = new WeakMap();      // text node -> what this script last put there
  const originalAttrs = new WeakMap();  // element -> { attribute: English value }
  const shownAttrs = new WeakMap();     // element -> { attribute: value this script last set }

  let lang = 'en';
  try {
    if (localStorage.getItem('residentLanguage') === 'fil') lang = 'fil';
  } catch { /* storage blocked: stay in English */ }

  const lookup = english => (lang === 'fil' && Object.prototype.hasOwnProperty.call(FIL, english) ? FIL[english] : null);

  // A sentence with values, e.g. t('Welcome, {name}', { name: 'Ana' }).
  window.t = (english, values = {}) => (lookup(english) || english)
    .replace(/\{(\w+)\}/g, (match, key) => (key in values ? String(values[key]) : match));
  window.currentLanguage = () => lang;

  // Never touch scripts, what residents type (text inside a text box), or marked user content.
  // A text box's placeholder and label are still translated.
  const skipped = node => {
    const isText = node.nodeType === Node.TEXT_NODE;
    const element = isText ? node.parentElement : node;
    return !element || Boolean(element.closest(isText ? 'script, style, textarea, [data-no-i18n]' : 'script, style, [data-no-i18n]'));
  };

  function translateTextNode(node) {
    if (skipped(node)) return;
    // Text the portal changed since our last edit is the new English original.
    if (!originalText.has(node) || node.nodeValue !== shownText.get(node)) originalText.set(node, node.nodeValue);
    const english = originalText.get(node);
    const trimmed = english.trim();
    const translated = trimmed ? lookup(trimmed) : null;
    const value = translated ? english.replace(trimmed, translated) : english;
    shownText.set(node, value);
    if (node.nodeValue !== value) node.nodeValue = value;
  }

  function translateAttributes(element) {
    if (skipped(element)) return;
    const originals = originalAttrs.get(element) || {};
    const shown = shownAttrs.get(element) || {};
    ATTRIBUTES.forEach(name => {
      if (!element.hasAttribute(name)) return;
      const current = element.getAttribute(name);
      if (!(name in originals) || current !== shown[name]) originals[name] = current;
      const value = lookup(originals[name].trim()) || originals[name];
      shown[name] = value;
      if (current !== value) element.setAttribute(name, value);
    });
    originalAttrs.set(element, originals);
    shownAttrs.set(element, shown);
  }

  function translateTree(root) {
    if (root.nodeType === Node.TEXT_NODE) {
      translateTextNode(root);
      return;
    }
    if (root.nodeType !== Node.ELEMENT_NODE) return;
    translateAttributes(root);
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      if (node.nodeType === Node.TEXT_NODE) translateTextNode(node);
      else translateAttributes(node);
    }
  }

  // Text the portal adds or changes later is translated as it appears.
  const observer = new MutationObserver(mutations => {
    mutations.forEach(mutation => {
      if (mutation.type === 'childList') mutation.addedNodes.forEach(translateTree);
      else if (mutation.type === 'characterData') translateTextNode(mutation.target);
      else if (mutation.type === 'attributes') translateAttributes(mutation.target);
    });
  });

  function updateSwitch() {
    document.documentElement.lang = lang === 'fil' ? 'fil' : 'en';
    document.querySelectorAll('[data-language]').forEach(button => {
      button.setAttribute('aria-pressed', String(button.dataset.language === lang));
    });
  }

  window.setResidentLanguage = next => {
    lang = next === 'fil' ? 'fil' : 'en';
    try { localStorage.setItem('residentLanguage', lang); } catch { /* not remembered */ }
    translateTree(document.body);
    updateSwitch();
    // Parts built from values (counts, dates, names) are rebuilt by the portal.
    document.dispatchEvent(new CustomEvent('residentlanguagechange'));
  };

  function start() {
    translateTree(document.body);
    updateSwitch();
    observer.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRIBUTES });
    document.addEventListener('click', event => {
      const button = event.target.closest('[data-language]');
      if (button) window.setResidentLanguage(button.dataset.language);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
