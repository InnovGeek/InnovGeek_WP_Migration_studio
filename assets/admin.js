(() => {
  'use strict';
  const cfg = window.IGBK_CONFIG;
  if (!cfg) return;
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  let busy = false;
  let currentFile = null;

  function tab(name) {
    $$('.igbk-nav-link').forEach(el => el.classList.toggle('is-active', el.dataset.tab === name));
    $$('.igbk-panel').forEach(el => el.classList.toggle('is-active', el.dataset.panel === name));
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  $$('.igbk-nav-link').forEach(btn => btn.addEventListener('click', () => tab(btn.dataset.tab)));
  $$('[data-goto]').forEach(btn => btn.addEventListener('click', () => tab(btn.dataset.goto)));

  const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
  const resumeBox = $('#igbk-resume-box');
  const resumeButton = $('#igbk-resume');
  const resumeDescription = $('#igbk-resume-description');
  const backupKey = 'igbk_backup_studio_full_export_v110'; // Keep key for V1.1 resumability.
  function storedJob() { try { return localStorage.getItem(backupKey) || ''; } catch (_) { return ''; } }
  let activeBackupId = resumeButton?.dataset.job || storedJob();
  function persistBackup(id) {
    activeBackupId = id || '';
    try { if (id) localStorage.setItem(backupKey, id); else localStorage.removeItem(backupKey); } catch (_) {}
    if (resumeBox) resumeBox.hidden = !id;
    if (resumeButton) resumeButton.dataset.job = id || '';
  }
  function setResumeDescription(text) { if (resumeDescription && text) resumeDescription.textContent = text; }
  if (activeBackupId && resumeBox) resumeBox.hidden = false;

  async function api(method, values = {}, blob = null) {
    const form = new FormData();
    form.append('action', 'igbk_' + method);
    form.append('nonce', cfg.nonce);
    Object.entries(values).forEach(([k, v]) => form.append(k, String(v)));
    if (blob) form.append('chunk', blob, 'part.bin');
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timeout = controller ? setTimeout(() => controller.abort(), 45000) : null;
    let resp;
    try {
      resp = await fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body: form, cache: 'no-store', signal: controller?.signal });
    } catch (e) {
      const error = new Error(e?.name === 'AbortError'
        ? 'Le serveur n’a pas répondu dans les 45 secondes. Une reprise sera tentée.'
        : 'La requête vers le serveur a été interrompue. Une reconnexion sera tentée.');
      error.transient = true;
      throw error;
    } finally { if (timeout) clearTimeout(timeout); }
    // A proxy may return an HTML error page before PHP/WordPress runs.
    if (resp.status === 413) {
      const error = new Error('Le serveur refuse ce bloc (HTTP 413). Réduction automatique de sa taille…');
      error.tooLarge = true;
      error.httpStatus = 413;
      throw error;
    }
    let json;
    try { json = await resp.json(); }
    catch (_) {
      const error = new Error('Réponse serveur non valide (HTTP ' + resp.status + '). Consultez les journaux PHP / serveur si le problème persiste.');
      error.transient = resp.status >= 500 || resp.status === 200 || resp.status === 405 || resp.status === 429;
      error.httpStatus = resp.status;
      throw error;
    }
    if (blob && resp.status === 400 && json === 0) {
      // WordPress often responds with 0 if PHP discarded an oversized POST,
      // because the request no longer has an `action` field.
      const error = new Error('Le serveur a rejeté ce bloc (requête PHP trop grande).');
      error.tooLarge = true;
      error.httpStatus = 400;
      throw error;
    }
    if (!resp.ok || !json?.success) {
      const message = json?.data?.message || (resp.status === 403 ? 'Session WordPress expirée. Rechargez la page.' : 'Le serveur a refusé l’opération.');
      const error = new Error(message + (resp.status >= 500 ? ' (HTTP ' + resp.status + ')' : ''));
      error.transient = resp.status === 429 || resp.status >= 500 || /déjà en cours|already in progress|Bloc temporaire inaccessible|Bloc de fichier non reçu|Verrou de transfert indisponible/i.test(message);
      error.httpStatus = resp.status;
      throw error;
    }
    return json.data;
  }

  // Only tick calls are replayed: they use a server-side lock and checkpoint.
  // All other requests retain their original failure behavior.
  async function reliableTick(id, previous) {
    for (let failures = 0; ; failures++) {
      try { return await api('tick', { job: id }); }
      catch (e) {
        if (!e.transient || failures >= 5) {
          if (failures >= 5) e.message += ' Vous pouvez fermer cette fenêtre et cliquer sur « Reprendre l’exportation ».';
          throw e;
        }
        const retry = failures + 1;
        updateProgress(previous?.percent ?? 0, 'Serveur indisponible : tentative ' + retry + '/6…');
        await sleep(Math.min(850 * Math.pow(2, failures), 7000));
        try {
          const current = await api('status', { job: id });
          if (current.phase === 'error' || current.phase === 'done' || current.phase === 'ready'
              || current.percent !== previous?.percent || current.message !== previous?.message) {
            return current;
          }
        } catch (_) { /* Temporary outage: retry the same checkpointed job. */ }
      }
    }
  }

  const progress = $('#igbk-progress');
  const progressTitle = $('#igbk-progress-title');
  const progressMessage = $('#igbk-progress-message');
  const progressPct = $('#igbk-progress-pct');
  const progressBar = $('#igbk-progress-bar');
  const progressError = $('#igbk-progress-error');
  const progressClose = $('#igbk-progress-close');
  const progressResume = $('#igbk-progress-resume');
  const stopwatch = $('#igbk-stopwatch');
  const stopwatchValue = $('#igbk-stopwatch-value');
  const stopwatchLabel = $('#igbk-stopwatch-label');
  const stopwatchKey = 'igbk_stopwatch_active_1214';
  let stopwatchType = '';
  let stopwatchJob = '';
  let stopwatchElapsed = 0;
  let stopwatchSince = null;
  let stopwatchInterval = null;
  let stopwatchCompleted = false;
  const stopwatchNow = () => Date.now();
  function stopwatchStorageRead(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (_) { return null; }
  }
  function stopwatchStorageWrite(key, state) {
    try { localStorage.setItem(key, JSON.stringify(state)); } catch (_) { /* Storage may be disabled. */ }
  }
  function stopwatchStorageRemove(key) {
    try { localStorage.removeItem(key); } catch (_) { /* Storage may be disabled. */ }
  }
  function stopwatchFormat(ms) {
    const seconds = Math.floor(Math.max(0, ms) / 1000);
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const rest = seconds % 60;
    return (hours ? String(hours).padStart(2, '0') + ':' : '') + String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
  }
  function stopwatchTotal() {
    return stopwatchElapsed + (stopwatchSince === null ? 0 : Math.max(0, stopwatchNow() - stopwatchSince));
  }
  function stopwatchDisplay() {
    if (!stopwatchValue || !stopwatchType) return;
    stopwatchValue.textContent = stopwatchFormat(stopwatchTotal());
  }
  function stopwatchSave() {
    if (stopwatchType === 'backup' && !stopwatchCompleted) {
      stopwatchStorageWrite(stopwatchKey, {job: stopwatchJob, elapsed: stopwatchTotal()});
    }
  }
  function stopwatchPause() {
    if (stopwatchSince !== null) stopwatchElapsed = stopwatchTotal();
    stopwatchSince = null;
    if (stopwatchInterval !== null) clearInterval(stopwatchInterval);
    stopwatchInterval = null;
    stopwatchDisplay();
    stopwatchSave();
  }
  function stopwatchBegin(type, jobId = '', continuing = false) {
    if (stopwatchType === type && !stopwatchCompleted) {
      if (jobId) stopwatchJob = jobId;
      if (stopwatchSince === null) stopwatchSince = stopwatchNow();
    } else {
      stopwatchPause();
      stopwatchType = type;
      stopwatchJob = jobId;
      stopwatchCompleted = false;
      stopwatchElapsed = 0;
      if (continuing && type === 'backup' && jobId) {
        const saved = stopwatchStorageRead(stopwatchKey);
        if (saved && saved.job === jobId && Number.isFinite(saved.elapsed) && saved.elapsed >= 0) {
          stopwatchElapsed = saved.elapsed;
        }
      }
      stopwatchSince = stopwatchNow();
    }
    if (stopwatch) stopwatch.hidden = false;
    if (stopwatchLabel) stopwatchLabel.textContent = 'Temps écoulé';
    if (stopwatchInterval !== null) clearInterval(stopwatchInterval);
    stopwatchInterval = setInterval(() => { stopwatchDisplay(); stopwatchSave(); }, 500);
    stopwatchDisplay();
    stopwatchSave();
  }
  function stopwatchFinish() {
    if (!stopwatchType || stopwatchCompleted) return;
    stopwatchPause();
    stopwatchCompleted = true;
    if (stopwatchType === 'backup') stopwatchStorageRemove(stopwatchKey);
    if (stopwatchLabel) stopwatchLabel.textContent = 'Durée totale';
    stopwatchDisplay();
  }
  window.addEventListener('pagehide', () => stopwatchPause());
  let onProgressClose = null;
  function openProgress(title, category) {
    progress.hidden = false;
    progressTitle.textContent = title;
    $('#igbk-progress-category').textContent = category === 'EXPORTATION' ? 'SAUVEGARDE' : category;
    progressMessage.textContent = 'Initialisation…';
    progressPct.textContent = '0%';
    progressBar.style.width = '0%';
    progressError.hidden = true;
    progressClose.hidden = true;
    if (progressResume) progressResume.hidden = true;
    if (stopwatch) stopwatch.hidden = !['EXPORTATION', 'RESTAURATION', 'REPRISE DE L’EXPORTATION'].includes(category);
  }
  function updateProgress(percent, message) {
    const pct = Math.max(0, Math.min(100, Number(percent) || 0));
    progressPct.textContent = pct + '%';
    progressBar.style.width = pct + '%';
    progressMessage.textContent = message || 'Traitement en cours…';
  }
  function stopProgress(title, error, shouldReload = false) {
    if (error && stopwatchSince !== null) stopwatchPause();
    progressTitle.textContent = title;
    progressError.hidden = !error;
    if (error) progressError.textContent = error;
    progressClose.hidden = false;
    onProgressClose = shouldReload ? () => window.location.reload() : () => { progress.hidden = true; };
  }
  progressClose.addEventListener('click', () => {
    if (onProgressClose) onProgressClose();
    if (!onProgressClose) progress.hidden = true;
    busy = false;
  });

  function ask(title, description, requireTyping, action) {
    const layer = $('#igbk-modal');
    const input = $('#igbk-modal-confirm-input');
    const row = $('#igbk-modal-confirm-row');
    const accept = $('#igbk-modal-accept');
    $('#igbk-modal-title').textContent = title;
    $('#igbk-modal-description').textContent = description;
    row.hidden = !requireTyping;
    input.value = '';
    accept.disabled = requireTyping;
    const change = () => { accept.disabled = input.value.trim() !== 'RESTAURER'; };
    input.oninput = change;
    const clean = () => { layer.hidden = true; input.oninput = null; accept.onclick = null; $('#igbk-modal-cancel').onclick = null; };
    $('#igbk-modal-cancel').onclick = clean;
    accept.onclick = () => { if (requireTyping && input.value.trim() !== 'RESTAURER') return; clean(); action(); };
    layer.hidden = false;
    if (requireTyping) input.focus();
  }

  async function runTicks(job, title, category) {
    openProgress(title, category);
    if (category === 'EXPORTATION') stopwatchBegin('backup', job.id, true);
    if (category === 'RESTAURATION') stopwatchBegin('restore', job.id, true);
    busy = true;
    try {
      while (true) {
        job = await reliableTick(job.id, job);
        updateProgress(job.percent, job.message);
        if (category === 'EXPORTATION') setResumeDescription(job.message + ' · ' + job.percent + '%');
        if (job.phase === 'error') throw new Error(job.error || job.message || 'Échec du traitement.');
        if (job.phase === 'ready') {
          progress.hidden = true;
          busy = false;
          askRestore(job);
          return;
        }
        if (job.phase === 'done') {
          if (category === 'EXPORTATION') persistBackup('');
          updateProgress(100, job.message);
          if (category === 'EXPORTATION' || category === 'RESTAURATION') stopwatchFinish();
          stopProgress(category === 'RESTAURATION' ? 'Restauration terminée' : 'Sauvegarde terminée', '', true);
          return;
        }
        await sleep(20);
      }
    } catch (e) {
      if (category === 'EXPORTATION' || category === 'RESTAURATION') stopwatchPause();
      stopProgress('Opération interrompue', e.message);
      if (category === 'EXPORTATION' && activeBackupId && progressResume) {
        progressResume.hidden = false;
        progressResume.onclick = () => { busy = false; resumeBackup(); };
      }
    }
  }

  async function resumeBackup() {
    if (busy || !activeBackupId) return;
    busy = true;
    openProgress('Recherche du dernier point de sauvegarde', 'REPRISE DE L’EXPORTATION');
    stopwatchBegin('backup', activeBackupId, true);
    try {
      const job = await api('status', { job: activeBackupId });
      if (job.type !== 'backup') throw new Error('Identifiant de sauvegarde invalide.');
      if (job.phase === 'done') { stopwatchFinish(); updateProgress(100, 'Sauvegarde terminée.'); persistBackup(''); stopProgress('Sauvegarde terminée', '', true); return; }
      if (job.phase === 'error') throw new Error('Cette exportation a échoué côté serveur : ' + (job.error || job.message) + '. Un nouvel export est nécessaire après correction.');
      await runTicks(job, 'Copie du site en cours', 'EXPORTATION');
    } catch (e) {
      stopwatchPause();
      stopProgress('Reprise impossible', e.message);
    }
  }
  resumeButton?.addEventListener('click', resumeBackup);
  const deleteResumeButton = $('#igbk-delete-resume');
  deleteResumeButton?.addEventListener('click', () => {
    const id = deleteResumeButton.dataset.job || activeBackupId;
    if (busy || !id) return;
    ask('Supprimer cette reprise ?', 'La sauvegarde manuelle interrompue sera supprimée. Vous pourrez ensuite relancer une nouvelle sauvegarde complète.', false, async () => {
      if (busy) return;
      busy = true;
      openProgress('Suppression de la reprise', 'MAINTENANCE');
      try {
        await api('delete', { job: id });
        persistBackup('');
        if (deleteResumeButton) deleteResumeButton.dataset.job = '';
        setResumeDescription('');
        updateProgress(100, 'Sauvegarde interrompue supprimée.');
        stopProgress('Suppression terminée', '', true);
      } catch (e) { stopProgress('Suppression impossible', e.message); }
    });
  });

  async function createBackup() {
    if (busy) return;
    busy = true;
    openProgress('Copie du site en cours', 'EXPORTATION');
    stopwatchType = ''; // A new manual backup is a new measurement.
    stopwatchBegin('backup');
    try {
      const job = await api('start_backup');
      persistBackup(job.id);
      setResumeDescription(job.message);
      await runTicks(job, 'Copie du site en cours', 'EXPORTATION');
    } catch (e) {
      stopwatchPause();
      stopProgress('Sauvegarde impossible', e.message);
    }
  }
  $('#igbk-create')?.addEventListener('click', createBackup);

  const chooser = $('#igbk-file');
  const zone = $('#igbk-drop');
  $('#igbk-choose')?.addEventListener('click', e => { e.stopPropagation(); chooser.click(); });
  zone?.addEventListener('click', e => { if (!e.target.closest('button')) chooser.click(); });
  zone?.addEventListener('keydown', e => {
    if (e.target === zone && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); chooser.click(); }
  });
  for (const eventName of ['dragenter', 'dragover']) {
    zone?.addEventListener(eventName, e => { e.preventDefault(); zone.classList.add('is-dragover'); });
  }
  for (const eventName of ['dragleave', 'drop']) {
    zone?.addEventListener(eventName, e => { e.preventDefault(); zone.classList.remove('is-dragover'); });
  }
  zone?.addEventListener('drop', e => { if (e.dataTransfer.files?.[0]) importFile(e.dataTransfer.files[0]); });
  chooser?.addEventListener('change', () => { if (chooser.files?.[0]) importFile(chooser.files[0]); chooser.value = ''; });

  // A 64 KiB SHA-256 fingerprint ensures the resumed upload is the same file.
  // The pure-JS fallback works on http://*.local, where SubtleCrypto may be unavailable.
  // This is not the final full-archive integrity check, which still runs on the server.
  function sha256Fallback(source) {
    const K = [
      0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
      0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
      0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
      0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
      0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
      0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
      0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
      0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2
    ];
    const h = [0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19];
    const size = Math.ceil((source.length + 9) / 64) * 64;
    const data = new Uint8Array(size);
    data.set(source); data[source.length] = 0x80;
    const view = new DataView(data.buffer);
    const bits = source.length * 8;
    view.setUint32(size - 8, Math.floor(bits / 0x100000000), false);
    view.setUint32(size - 4, bits >>> 0, false);
    const w = new Uint32Array(64);
    const r = (x, n) => (x >>> n) | (x << (32 - n));
    for (let start = 0; start < size; start += 64) {
      for (let i = 0; i < 16; i++) w[i] = view.getUint32(start + i * 4, false);
      for (let i = 16; i < 64; i++) {
        const a = w[i - 15], b = w[i - 2];
        const s0 = r(a, 7) ^ r(a, 18) ^ (a >>> 3);
        const s1 = r(b, 17) ^ r(b, 19) ^ (b >>> 10);
        w[i] = (w[i - 16] + s0 + w[i - 7] + s1) >>> 0;
      }
      let [a,b,c,d,e,f,g,j] = h;
      for (let i = 0; i < 64; i++) {
        const t1 = (j + (r(e, 6) ^ r(e, 11) ^ r(e, 25)) + ((e & f) ^ (~e & g)) + K[i] + w[i]) >>> 0;
        const t2 = ((r(a, 2) ^ r(a, 13) ^ r(a, 22)) + ((a & b) ^ (a & c) ^ (b & c))) >>> 0;
        j = g; g = f; f = e; e = (d + t1) >>> 0;
        d = c; c = b; b = a; a = (t1 + t2) >>> 0;
      }
      [a,b,c,d,e,f,g,j].forEach((v,i) => { h[i] = (h[i] + v) >>> 0; });
    }
    return h.map(v => v.toString(16).padStart(8, '0')).join('');
  }
  async function fileFingerprint(file) {
    try {
      const bytes = new Uint8Array(await file.slice(0, Math.min(65536, file.size)).arrayBuffer());
      if (window.crypto?.subtle) {
        try {
          const digest = await window.crypto.subtle.digest('SHA-256', bytes);
          return Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('');
        } catch (_) { /* Use fallback. */ }
      }
      return sha256Fallback(bytes);
    } catch (_) { return ''; }
  }

  /** Upload a Blob as a direct HTTP body. No PHP multipart temporary file.
   *  The authenticated WordPress AJAX endpoint streams it to private storage.
   */
  async function uploadRawChunk(id, offset, piece) {
    const params = new URLSearchParams({
      action: 'igbk_upload_chunk_raw', job: id, offset: String(offset), length: String(piece.size)
    });
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timeout = controller ? setTimeout(() => controller.abort(), 45000) : null;
    let resp;
    try {
      resp = await fetch(cfg.url + (cfg.url.includes('?') ? '&' : '?') + params.toString(), {
        method: 'POST', credentials: 'same-origin', body: piece, cache: 'no-store',
        headers: { 'Content-Type': 'application/octet-stream', 'X-IGBK-Nonce': cfg.nonce },
        signal: controller?.signal
      });
    } catch (e) {
      const error = new Error(e?.name === 'AbortError'
        ? 'Le serveur ne répond pas après 45 secondes. Reprise depuis le dernier bloc confirmé.'
        : 'Transfert interrompu. Nouvelle tentative depuis le dernier bloc confirmé.');
      error.transient = true;
      throw error;
    } finally { if (timeout) clearTimeout(timeout); }
    if (resp.status === 413) {
      const error = new Error('Bloc refusé par le serveur (HTTP 413).');
      error.tooLarge = true;
      error.httpStatus = 413;
      throw error;
    }
    let data;
    try { data = await resp.json(); }
    catch (_) {
      const error = new Error('Réponse HTTP ' + resp.status + ' invalide pendant le transfert direct.');
      error.httpStatus = resp.status;
      error.fallback = [403, 404, 405, 415, 501].includes(resp.status);
      error.transient = resp.status >= 500 || resp.status === 429;
      throw error;
    }
    if (!resp.ok || !data?.success) {
      const message = data?.data?.message || 'Le serveur a refusé ce bloc.';
      const error = new Error(message + ' (HTTP ' + resp.status + ')');
      error.httpStatus = resp.status;
      error.fallback = [404, 405, 415, 501].includes(resp.status);
      error.transient = resp.status === 429 || resp.status >= 500 ||
        /incomplet|indisponible|temporaire|verrou|bloqué/i.test(message);
      throw error;
    }
    return data.data;
  }

  async function uploadStatus(id) {
    const state = await api('status', { job: id });
    if (state.type !== 'import' || state.phase !== 'receiving')
      throw new Error('La tâche d’importation n’accepte plus de blocs.');
    const value = Number(state.received);
    if (!Number.isSafeInteger(value) || value < 0)
      throw new Error('Position de transfert invalide côté serveur.');
    return value;
  }

  async function importFile(file) {
    if (busy) return;
    if (!file.name.toLowerCase().endsWith('.igbk')) {
      ask('Format non compatible', 'Ce plugin utilise le format .igbk. Les archives All-in-One WP Migration (.wpress) ne peuvent pas être importées directement.', false, () => {});
      return;
    }
    busy = true;
    openProgress('Importation de l’archive', 'IMPORTATION PAR BLOCS');
    try {
      const fingerprint = await fileFingerprint(file);
      const job = await api('start_upload', { filename: file.name, size: file.size, fingerprint });
      const id = job.id;
      let offset = Number(job.received) || 0;
      if (offset < 0 || offset > file.size || !Number.isSafeInteger(offset))
        throw new Error('Position de reprise incorrecte.');
      // Start quickly, but stay well under common proxy/PHP upload limits.
      // Smaller blocks are selected only after an actual HTTP 413 refusal.
      let chunkBytes = Math.max(65536, Math.min(Number(cfg.chunk) || 1048576, 2 * 1048576));
      const minimumBytes = 65536;
      let useLegacyMultipart = false;
      updateProgress(Math.floor(offset / file.size * 100),
        (offset ? 'Reprise de l’importation : ' : 'Transfert : ') + human(offset) + ' / ' + human(file.size));
      while (offset < file.size) {
        const length = Math.min(chunkBytes, file.size - offset);
        const piece = file.slice(offset, offset + length);
        let failures = 0;
        while (true) {
          try {
            const updated = useLegacyMultipart
              ? await api('upload_chunk', { job: id, offset }, piece)
              : await uploadRawChunk(id, offset, piece);
            const received = Number(updated.received);
            if (received !== offset + length)
              throw new Error('Le serveur a confirmé une taille de bloc incohérente.');
            offset = received;
            updateProgress(Math.floor(offset / file.size * 100), 'Transfert : ' + human(offset) + ' / ' + human(file.size));
            break;
          } catch (e) {
            if (e.fallback && !useLegacyMultipart) {
              useLegacyMultipart = true;
              chunkBytes = Math.min(chunkBytes, 1048576);
              updateProgress(Math.floor(offset / file.size * 100),
                'Transfert direct refusé par l’hébergement, utilisation du mode classique…');
              break;
            }
            // The server might have committed the block but lost the response:
            // always consult its checkpoint before deciding whether to resend.
            let checkpoint = null;
            if (e.tooLarge || e.transient) {
              try { checkpoint = await uploadStatus(id); } catch (_) { /* Retry below. */ }
            }
            if (checkpoint !== null && checkpoint > offset) {
              if (checkpoint !== offset + length)
                throw new Error('Reprise impossible : position reçue incohérente.');
              offset = checkpoint;
              updateProgress(Math.floor(offset / file.size * 100), 'Bloc reçu, reprise confirmée : ' + human(offset));
              break;
            }
            if (e.tooLarge) {
              if (chunkBytes <= minimumBytes) {
                throw new Error('HTTP 413 même avec un bloc de 64 Ko. L’hébergeur ou un pare-feu bloque les requêtes ; vérifiez la limite HTTP du serveur (Nginx/Apache/WAF).');
              }
              chunkBytes = Math.max(minimumBytes, Math.floor(chunkBytes / 2));
              updateProgress(Math.floor(offset / file.size * 100),
                'HTTP 413 : nouveau bloc de ' + human(chunkBytes) + ', reprise à ' + human(offset) + '…');
              // Restart outer loop with a newly sliced, smaller block.
              break;
            }
            if (!e.transient || failures >= 5) throw e;
            failures++;
            updateProgress(Math.floor(offset / file.size * 100),
              'Connexion interrompue, tentative ' + failures + '/5 au point ' + human(offset) + '…');
            await sleep(Math.min(800 * Math.pow(2, failures - 1), 6000));
          }
        }
      }
      const completed = await api('finish_upload', { job: id });
      await runTicks(completed, 'Vérification de l’archive', 'VALIDATION SHA-256');
    } catch (e) { stopProgress('Importation interrompue', e.message); }
  }
  const importRetry = $('#igbk-retry-import');
  async function retryStoredImport(id) {
    if (busy) return;
    busy = true;
    openProgress('Récupération de l’archive importée', 'VALIDATION SHA-256');
    try {
      const job = await api('finish_upload', { job: id });
      await runTicks(job, 'Vérification de l’archive existante', 'VALIDATION SHA-256');
    } catch (e) { stopProgress('Reprise impossible', e.message); }
  }
  importRetry?.addEventListener('click', () => retryStoredImport(importRetry.dataset.job));
  const pendingDelete = $('#igbk-delete-pending-import');
  pendingDelete?.addEventListener('click', () => {
    if (busy) return;
    ask('Supprimer cette importation ?', 'L’importation sera retirée immédiatement. Le nettoyage physique sera effectué ensuite par WordPress. Vous devrez réimporter l’archive pour la restaurer.', false, async () => {
      if (busy) return;
      busy = true;
      openProgress('Retrait de l’importation', 'MAINTENANCE');
      updateProgress(10, 'Retrait du fichier de la liste…');
      try {
        await api('delete', { job: pendingDelete.dataset.job });
        updateProgress(100, 'Importation retirée. Le nettoyage du disque est planifié.');
        stopProgress('Importation retirée', '', true);
      } catch (e) { stopProgress('Suppression impossible', e.message); }
    });
  });
  function human(bytes) {
    const units = ['o', 'Ko', 'Mo', 'Go', 'To'];
    let i = 0;
    while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
    return bytes.toFixed(i ? 1 : 0) + ' ' + units[i];
  }

  $$('.igbk-restore-archive').forEach(btn => btn.addEventListener('click', async () => {
    if (busy) return;
    busy = true;
    openProgress('Préparation de la restauration', 'VALIDATION SHA-256');
    try {
      const job = await api('verify', { archive: btn.dataset.id });
      await runTicks(job, 'Vérification de l’archive', 'VALIDATION SHA-256');
    } catch (e) { stopProgress('Validation impossible', e.message); }
  }));
  function askRestore(job) {
    const migration = Boolean(job.migration);
    const domainInfo = migration ? `\nMigration : ${job.source_site} → ${job.target_site}. Les URL SQL courantes seront adaptées. Vérifiez les liens et les données propres à vos extensions après restauration.` : '';
    ask('Restaurer cette sauvegarde ?', 'Attention : les fichiers wp-content et les tables de la base de données du site actuel seront remplacés. Les anciennes tables SQL restent sous des noms de secours. Sauvegardez d’abord la destination et testez sur une préproduction. Le cœur WordPress et wp-config.php ne sont pas restaurés.' + domainInfo, true, async () => {
      if (busy) return;
      busy = true;
      openProgress('Lancement de la restauration', 'RESTAURATION');
      stopwatchType = ''; // The restoration clock starts only after confirmation.
      stopwatchBegin('restore', job.id);
      try {
        const started = await api('begin_restore', { job: job.id, confirmation: 'RESTAURER' });
        await runTicks(started, 'Restauration du site', 'RESTAURATION');
      } catch (e) { stopwatchPause(); stopProgress('Restauration interrompue', e.message); }
    });
  }
  $$('.igbk-delete-archive').forEach(btn => btn.addEventListener('click', () => {
    if (busy) return;
    ask('Supprimer cette archive ?', 'Cette archive sera retirée de la liste immédiatement. L’espace disque sera libéré après le nettoyage automatique.', false, async () => {
      if (busy) return;
      busy = true;
      openProgress('Retrait de l’archive', 'MAINTENANCE');
      updateProgress(10, 'Retrait de l’archive de la liste…');
      try {
        await api('delete', { archive: btn.dataset.id });
        updateProgress(100, 'Archive retirée. Le nettoyage du disque est planifié.');
        stopProgress('Archive retirée', '', true);
      } catch (e) { stopProgress('Suppression impossible', e.message); }
    });
  }));

  // Scheduled backups are one complete-site mode too; this form controls timing,
  // not scope. Cron work continues on the server after this page is closed.
  const scheduleForm = $('#igbk-schedule-form');
  const frequency = $('#igbk-frequency');
  const weeklyField = $('#igbk-weekday-row');
  const monthlyField = $('#igbk-monthday-row');
  function syncScheduleFields() {
    if (weeklyField && frequency) weeklyField.hidden = frequency.value !== 'weekly';
    if (monthlyField && frequency) monthlyField.hidden = frequency.value !== 'monthly';
  }
  frequency?.addEventListener('change', syncScheduleFields);
  syncScheduleFields();
  scheduleForm?.addEventListener('submit', async e => {
    e.preventDefault();
    const button = $('#igbk-save-schedule');
    const feedback = $('#igbk-schedule-feedback');
    if (button.disabled) return;
    button.disabled = true;
    feedback.classList.remove('is-error');
    feedback.textContent = 'Enregistrement…';
    try {
      const result = await api('save_schedule', {
        frequency: frequency.value,
        time: $('#igbk-time').value,
        weekday: $('#igbk-weekday').value,
        monthday: $('#igbk-monthday').value,
        retention: $('#igbk-retention').value
      });
      feedback.textContent = 'Programmation enregistrée. Les prochaines sauvegardes utiliseront ces paramètres.';
      const next = $('#igbk-next-run');
      if (next) next.textContent = result.next_formatted || 'Non programmée';
    } catch (err) {
      feedback.classList.add('is-error');
      feedback.textContent = err.message;
    } finally { button.disabled = false; }
  });
})();
