// listado_depositos.js (completo, integrado con polling y notificaciones)
// ---------------------------------------------------------------------
// Versión modificada: añade parsing robusto, deduplicación, mark-seen mejorado.
// Cambios principales:
// - ACTIONABLE_TYPES: solo notificamos eventos accionables (evita notis por CHAT_OPEN).
// - markSeen mejorado (envía documento_usuario si está disponible y actualiza estado local).
// - Al abrir modal chat: marcamos visto *antes* de cargar historial (optimista).
// - Polling ignora tipos no accionables y eventos enviados por el propio usuario.
// - Mejora de uniqToastId para evitar colisiones.
// ---------------------------------------------------------------------

let chatDepositoActual = "";   // id_deposito (numérico) o string si viene así
let chatNDepositoActual = "";  // n_deposito (13 dígitos)
let estadoActualChat   = 0;

// --- Polling / notificaciones (nuevas variables globales) ---
window.lastTimestampByDep = window.lastTimestampByDep || {}; // map id_deposito -> 'YYYY-MM-DD HH:MM:SS' (persistente en sesión)
let openDepId = null;        // id_deposito actualmente abierto en modal (num)
let pollingTimer = null;
const POLL_INTERVAL_MS = 8000; // ajustar si querés

// tipos que consideramos "accionables" y que deben generar notificaciones
const ACTIONABLE_TYPES = new Set(['NOTIFICACION','CHAT']);

// Dedup y pruning para evitar toasts repetidos
window._shownMessages = window._shownMessages || new Set();
window._shownMessagesPruneTimer = window._shownMessagesPruneTimer || null;

// Rastreador para PDFs clickeados por fila: map de id_deposito -> {orden_pdf: bool, resolucion_pdf: bool}
window._pdfClickedTracker = window._pdfClickedTracker || {};

// ----------------- UTIL HELPERS -----------------
function keyFor(dep) { return String(dep); }

function parseTsToMillis(ts) {
  if (ts === undefined || ts === null) return NaN;
  if (typeof ts === 'number') return ts;
  const s = String(ts).trim();
  // Acepta "YYYY-MM-DD HH:MM:SS" -> convertir a ISO pasando el espacio por T
  if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/.test(s)) {
    return Date.parse(s.replace(/\s+/, 'T'));
  }
  // si ya está en ISO o parecido
  const parsed = Date.parse(s);
  return isNaN(parsed) ? NaN : parsed;
}

function shouldShowMessage(dep, msgTs, uniqueId) {
  // false si ya lo mostramos por id único
  if (uniqueId && window._shownMessages.has(uniqueId)) return false;
  const msgMs = parseTsToMillis(msgTs);
  const lastMs = parseTsToMillis(window.lastTimestampByDep[keyFor(dep)]);
  if (!isFinite(msgMs)) return true; // si no hay fecha, mostrala (opcional)
  return !isFinite(lastMs) || msgMs > lastMs;
}

function markShown(uniqueId) {
  if (!uniqueId) return;
  window._shownMessages.add(uniqueId);
  // lazy prune para no crecer infinito
  if (!window._shownMessagesPruneTimer) {
    window._shownMessagesPruneTimer = setInterval(() => {
      if (window._shownMessages.size > 5000) {
        const arr = Array.from(window._shownMessages).slice(-2000);
        window._shownMessages.clear();
        arr.forEach(x => window._shownMessages.add(x));
      }
    }, 60000);
  }
}

// HELPER: sanitizar HTML para mostrar en modal (debug)
function escapeHtml(str) {
  if (str === undefined || str === null) return '';
  return String(str).replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
}

// ----------------- UI HELPERS -----------------
function abrirModal(titulo, contenido) {
  const mt = document.getElementById("modal-titulo");
  const mb = document.getElementById("modal-body");
  if (mt) mt.innerText = titulo;
  if (mb) mb.innerHTML = contenido;
  const m = document.getElementById("modal");
  if (m) m.style.display = "flex";
}

function cerrarModal() {
  const m = document.getElementById("modal");
  if (m) m.style.display = "none";
}

function cerrarModalChat() {
  const m = document.getElementById("modal-chat");
  if (m) m.style.display = "none";
  const cc = document.getElementById("chat-comentario");
  if (cc) cc.value = "";
  const ch = document.getElementById("chat-historial");
  if (ch) ch.innerHTML = "";
  const cs = document.getElementById("chat-botones-superiores");
  if (cs) cs.innerHTML = "";
  openDepId = null;
}

// Append de un solo mensaje (nuevo) en el chat DOM
function appendSingleMessageToChat(m) {
  const cont = document.getElementById('chat-historial');
  if (!cont) return;
  const div = document.createElement('div');
  div.style.margin = '6px 8px';
  div.style.padding = '6px 8px';
  div.style.borderRadius = '6px';
  div.style.maxWidth = '85%';
  div.style.clear = 'both';
  const isMe = (m.documento_usuario === usuarioActual);
  div.style.background = isMe ? '#dff0d8' : '#fff';
  div.style.float = isMe ? 'right' : 'left';
  const when = new Date(m.fecha_historial_deposito || m.fecha || Date.now()).toLocaleString();
  div.innerHTML = `<small style="color:#666">${escapeHtml(m.documento_usuario || '')} • ${when}</small><div style="margin-top:4px;">${escapeHtml(m.comentario_deposito || m.comentario || '')}</div>`;
  cont.appendChild(div);
  cont.scrollTop = cont.scrollHeight;
}

// ----------------- fetchWithFallback -----------------
// intenta múltiples rutas y devuelve { resp, url } para la primera que responde
async function fetchWithFallback(urls, options) {
  let lastErr = null;
  for (const url of urls) {
    try {
      const resp = await fetch(url, options);
      if (resp.status === 404) {
        lastErr = new Error(`404 Not Found: ${url}`);
        continue;
      }
      return { resp, url };
    } catch (err) {
      lastErr = err;
    }
  }
  throw lastErr || new Error('No se pudo contactar ninguna URL');
}

// Map endpoint names to fallback candidate URLs
function tryUrlsFor(name) {
  switch(name) {
    case 'get_last':
      return [
        "../api/get_last_by_depositos.php",
        "../code_back/back_deposito_get_last.php",
        "../api/get_last_by_depositos.php"
      ];
    case 'get_historial':
      return [
        "../api/get_historial_deposito.php",
        "../code_back/back_deposito_cargar_historial.php",
        "api/get_historial_deposito.php"
      ];
    case 'mark_seen':
      return [
        "../api/mark_seen.php",
        "../code_back/back_deposito_marcar_visto.php",
        "api/mark_seen.php"
      ];
    case 'add_historial':
      return [
        "../api/add_historial_deposito.php",
        "../code_back/back_deposito_enviar_historial.php",
        "api/add_historial_deposito.php"
      ];
    default:
      return [];
  }
}

// ----------------- Helpers de búsqueda / utils -----------------
function extractDepositFromFilename(filename) {
  if (!filename) return null;
  const basename = filename.includes('.') ? filename.substring(0, filename.lastIndexOf('.')) : filename;
  const s = basename;

  for (let i = 0; i <= s.length - 13; i++) {
    if (/\d/.test(s[i])) {
      const candidate = s.substr(i, 13);
      if (/^\d{13}$/.test(candidate)) {
        const nextChar = s[i + 13] || '';
        if (nextChar === '' || nextChar === '_' || !/\d/.test(nextChar)) {
          return candidate;
        }
      }
    }
  }
  const m = s.match(/\d{13}/g);
  return (m && m.length) ? m[m.length - 1] : null;
}

function findIdDepByDisplayedN(n) {
  if (!n) return null;
  const norm = String(n).replace(/\D/g, '');
  const rows = document.querySelectorAll("#tabla-depositos tbody tr");
  for (const tr of rows) {
    try {
      const dAttrs = [
        tr.dataset.ndep,
        tr.dataset.dep,
        tr.dataset.ndepOriginal,
        tr.dataset.ndeporiginal,
        tr.dataset.ndep || tr.dataset.ndep
      ].filter(Boolean);
      for (const a of dAttrs) {
        if (String(a).replace(/\D/g,'') === norm) {
          return tr.dataset.iddep || tr.dataset.id || tr.dataset.id_deposito || null;
        }
      }
      const td = tr.querySelector('.col-deposito');
      if (td && td.innerText && td.innerText.replace(/\D/g,'') === norm) {
        return tr.dataset.iddep || tr.dataset.id || tr.dataset.id_deposito || null;
      }
      const textMatch = tr.innerText || '';
      if (textMatch.replace(/\D/g,'').includes(norm) && (textMatch.match(/\d{6,}/) || []).length) {
        if (tr.dataset.iddep || tr.dataset.id || tr.dataset.id_deposito) {
          return tr.dataset.iddep || tr.dataset.id || tr.dataset.id_deposito;
        }
      }
    } catch (e) {
      console.warn("findIdDepByDisplayedN - fila check error:", e);
    }
  }
  return null;
}

// (la función cargarHistorial está definida más abajo - se mantuvo la única definición válida)

// Inyecta acciones del chat. idDep = id_deposito, nDep = n_deposito
// ------------------ Cargar historial (versión que muestra ROL – NOMBRE) ------------------
function cargarHistorial(dep) {
  
  const chat = document.getElementById("chat-historial");
  if (!chat) return Promise.resolve();
  chat.innerHTML = "<i>Cargando...</i>";

  const idDep = parseInt(dep, 10);
  if (!Number.isFinite(idDep) || idDep <= 0) {
    chat.innerHTML = `<div style="color:darkorange;">Falta id_deposito válido.</div>`;
    console.warn("cargarHistorial: id_deposito inválido =", dep);
    return Promise.resolve();
  }

  const urls = tryUrlsFor('get_historial');
  const body = `id_deposito=${encodeURIComponent(idDep)}`;

  return fetchWithFallback(urls, {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body
  })
    .then(async ({ resp, url }) => {
      const text = await resp.text();
      let data = null;
      try {
        data = text ? JSON.parse(text) : null;
      } catch (e) {
        data = null;
      }

      if (!data || !data.ok) {
        chat.innerHTML = `<div style="color:red;">Error al cargar historial.</div>`;
        return;
      }

      // Renderizar historial
      const arr = data.data || [];
      if (!Array.isArray(arr) || arr.length === 0) {
        chat.innerHTML = "<i>No hay mensajes.</i>";
        return;
      }

      chat.innerHTML = arr
        .map((m) => {
          const rolStr = escapeHtml(m.nombre_rol || "Usuario");
          const nombreStr = escapeHtml(`${m.nombre_persona || ""} ${m.apellido_persona || ""}`);
          const comentario = escapeHtml(m.comentario_deposito || "<i>Sin comentario</i>");
          const fecha = escapeHtml(m.fecha_historial_deposito || "");
          return `
            <div style="margin-bottom:10px;">
              <strong>${rolStr} – ${nombreStr}</strong>: ${comentario}<br>
              <small style="color:gray;">${fecha}</small>
            </div>
          `;
        })
        .join("");

      // Mostrar observación si existe
      const observacion = data.observacion || null;
      if (observacion) {
        const modalHeader = document.getElementById("chat-titulo");
        // Limpiar bloque de observación anterior si existe
        let existingObsBlock = modalHeader ? modalHeader.nextElementSibling : null;
        if (existingObsBlock && existingObsBlock.id === "chat-observacion-block") {
          existingObsBlock.remove();
        }

        // Crear nuevo bloque de observación
        if (modalHeader) {
          const observacionAlert = document.createElement("div");
          observacionAlert.id = "chat-observacion-block";
          observacionAlert.style.cssText =
            "background-color: #e1554bff; color: white; padding: 12px; margin: 10px 0; text-align: center; border-radius: 5px; font-weight: bold; font-size: 14px;";
          observacionAlert.innerHTML = `⚠️ OBSERVACIÓN: ${escapeHtml(observacion)}`;
          modalHeader.insertAdjacentElement("afterend", observacionAlert);
        }
      } else {
        // Si no hay observación, remover bloque anterior si existe
        const modalHeader = document.getElementById("chat-titulo");
        if (modalHeader) {
          let existingObsBlock = modalHeader.nextElementSibling;
          if (existingObsBlock && existingObsBlock.id === "chat-observacion-block") {
            existingObsBlock.remove();
          }
        }
      }
    })
    .catch((err) => {
      console.error("Error cargarHistorial:", err);
      chat.innerHTML = `<div style="color:red;">Error de red: ${escapeHtml(err.message || String(err))}</div>`;
    });
}

// Inyecta acciones del chat. idDep = id_deposito, nDep = n_deposito
function injectChatActions(idDep, state, nDep) {
  const cont = document.getElementById("chat-botones-superiores");
  if (!cont) return;
  cont.innerHTML = "";

  // helper para sanitizar HTML al mostrar respuestas de servidor
  function _escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, "&amp;")
              .replace(/</g, "&lt;")
              .replace(/>/g, "&gt;")
              .replace(/"/g, "&quot;")
              .replace(/'/g, "&#039;");
  }
  // alias por compatibilidad (el código previa usaba escapeHtml en varios lugares)
  const escapeHtml = _escapeHtml;

  // guardar referencias globales para usar en otros handlers si se necesita
  chatDepositoActual = idDep;
  chatNDepositoActual = nDep || "";

  // Nuevos grupos de estados:
  // Grupo A: 3,5,6,8,9 (comportamiento igual)
  // Grupo B: 2,7 (comportamiento igual)
  const stateAsGroupA = [3,5,6,8,9].includes(state);
  const stateAsGroupB = [2,7].includes(state);

  // Tratamos stateAsGroupA como comportamiento "3" (envío de mensajes, upload, etc.)
  const stateAs3 = stateAsGroupA;

  // ------------------ helpers específicos nuevos ------------------
  // Extrae nro de depósito de un filename con formato FIRMA_<NRO>_...
  // devuelve null si no encuentra
  // REEMPLAZAR por completo
function extractDepositFromFirmaFilename(filename) {
  if (!filename || typeof filename !== 'string') return null;
  const base = filename.split(/[\/\\]/).pop();          // último segmento
  const noExt = base.replace(/\.[^.]+$/, '');           // sin extensión
  const cleaned = noExt.replace(/\([^)]*\)$/, '');      // quita "(...)" al final si hay
  if (!/^FIRMA_/i.test(cleaned)) return null;

  // Queda: FIRMA_<TOKEN>[_...]
  const after = cleaned.slice(6);                       // quita "FIRMA_"
  const token = (after.split('_')[0] || '').replace(/\D/g, ''); // primer token solo dígitos

  if (token.length < 13) return null;                   // inválido si no llega a 13
  return token.slice(-13);                              // SIEMPRE los últimos 13
}


  // función que intenta obtener nro de depósito dado un nombre de archivo:
  // prioriza el formato FIRMA_, sino cae a extractDepositFromFilename (si existe globalmente)
  // REEMPLAZAR por completo
  function getDepositFromOrderFilename(fname) {
    const d = extractDepositFromFirmaFilename(fname);
    return d || null;
  }


  // ------------------ FIN helpers nuevos ------------------

  // Enviar comentario
  if (stateAs3 || (stateAsGroupB && typeof rolActual !== 'undefined' && rolActual === 2)) {
    const wrapper = document.getElementById("chat-comentario-wrapper");
    if (wrapper) wrapper.style.display = "block";
    const input = document.getElementById("chat-comentario");
    if (input) input.disabled = false;
    const btnEnv = document.createElement("button");
    btnEnv.textContent = "Enviar";
    btnEnv.className   = "chat-action-btn";
    btnEnv.onclick     = enviarMensaje; // tu función pública (está más abajo)
    cont.appendChild(btnEnv);
  } else {
    const wrapper = document.getElementById("chat-comentario-wrapper");
    if (wrapper) wrapper.style.display = "none";
    const input = document.getElementById("chat-comentario");
    if (input) input.disabled = true;
  }

  // Subir PDF + registrar orden (rol 3 y estadoAsGroupA)
  if (typeof rolActual !== 'undefined' && rolActual === 3 && stateAs3) {
    const btnPdf = document.createElement("button");
    btnPdf.textContent = "Subir Orden (PDF)";
    btnPdf.className   = "chat-action-btn btn-azul-oscuro";

    const onClickBtnPdf = () => {
      abrirModal("Subir Orden y Resolución (PDF)", `
        <form id="formOrdenPdf" enctype="multipart/form-data" style="text-align:center;">
          <div style="margin-bottom:8px;">
            <label style="font-weight:600;">Nro de Depósito (13 dígitos)</label><br>
            <input type="text" id="nDepositoInput" name="n_deposito" placeholder="Opcional: autoextraído desde el nombre del archivo de ORDEN" style="width:80%;padding:6px;" autocomplete="off" />
            <div id="nDepositNote" style="margin-top:6px;"></div>
          </div>

          <div id="ordenesContainer" style="margin-bottom:8px; text-align:left;">
            <label style="font-weight:600;">Orden de Pago (PDF) — debe comenzar con <strong>FIRMA_</strong></label><br>
            <div class="orden-file-row" style="margin-top:6px;">
              <input type="file" name="orden_pdf[]" class="orden-file" accept="application/pdf"/>
              <button type="button" class="remove-orden-btn chat-action-btn btn-gris" style="margin-left:8px; display:none;">Eliminar</button>
            </div>
          </div>

          <div style="margin-bottom:8px;">
            <button type="button" id="addOrdenBtn" class="chat-action-btn" style="margin-bottom:8px;">+ Agregar otra orden de pago</button>
          </div>

          <div id="resolucionesContainer" style="margin-bottom:8px; text-align:left;">
            <label style="font-weight:600;">Resolución (PDF) — OBLIGATORIA</label><br>
            <div class="resol-file-row" style="margin-top:6px;">
              <input type="file" name="resolucion_pdf[]" class="resol-file" accept="application/pdf"/>
              <button type="button" class="remove-resol-btn chat-action-btn btn-gris" style="margin-left:8px; display:none;">Eliminar</button>
            </div>
            <div style="font-size:0.85em;color:gray;margin-top:4px;">
              Nota: el número de depósito se extrae sólo desde el/los archivos de <strong>Orden de Pago</strong> (formato <code>FIRMA_&lt;NRO&gt;_...</code>).
            </div>
          </div>

          <div style="margin-bottom:8px;">
            <button type="button" id="addResolBtn" class="chat-action-btn" style="margin-bottom:8px;">+ Agregar otra resolución</button>
          </div>

          <div style="margin-top:12px;">
            <button type="submit" id="submitPdfBtn" class="chat-action-btn">Enviar archivos</button>
            <button type="button" id="cancelUploadBtn" class="chat-action-btn btn-rojo" style="margin-left:8px;">Cancelar</button>
          </div>
          <div id="uploadStatus" style="margin-top:10px;"></div>
        </form>
      `);

      // cerrar chat para destacar modal
      cerrarModalChat();

      // DOM refs
      const form            = document.getElementById("formOrdenPdf");
      const submitBtn       = document.getElementById("submitPdfBtn");
      const cancelBtn       = document.getElementById("cancelUploadBtn");
      const statusDiv       = document.getElementById("uploadStatus");
      const nDepositoInput  = document.getElementById("nDepositoInput");
      const nDepositNoteDiv = document.getElementById("nDepositNote");
      const ordenesContainer = document.getElementById("ordenesContainer");
      const resolucionesContainer = document.getElementById("resolucionesContainer");
      const modalEl         = document.getElementById("modal");
      const modalBodyEl     = document.getElementById("modal-body");

      // evitar cerrar modal si se hace click dentro del body
      const blockBubbling = (ev) => ev.stopPropagation();
      if (modalBodyEl) modalBodyEl.addEventListener('click', blockBubbling);
      if (form)        form.addEventListener('click', blockBubbling);

      // Add orden button
      const addOrdenBtn = document.getElementById('addOrdenBtn');
      addOrdenBtn.addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'orden-file-row';
        row.style.marginTop = '6px';
        row.innerHTML = `
          <input type="file" name="orden_pdf[]" class="orden-file" accept="application/pdf" />
          <button type="button" class="remove-orden-btn chat-action-btn btn-gris" style="margin-left:8px;">Eliminar</button>
        `;
        ordenesContainer.appendChild(row);
        const rem = row.querySelector('.remove-orden-btn');
        rem.addEventListener('click', (ev) => { ev.preventDefault(); row.remove(); });
      });

      // Add resol button
      const addResolBtn = document.getElementById('addResolBtn');
      addResolBtn.addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'resol-file-row';
        row.style.marginTop = '6px';
        row.innerHTML = `
          <input type="file" name="resolucion_pdf[]" class="resol-file" accept="application/pdf" />
          <button type="button" class="remove-resol-btn chat-action-btn btn-gris" style="margin-left:8px;">Eliminar</button>
        `;
        resolucionesContainer.appendChild(row);
        const rem = row.querySelector('.remove-resol-btn');
        rem.addEventListener('click', (ev) => { ev.preventDefault(); row.remove(); });
      });

      // onFileChange -> solo extrae de archivos de ORDEN y validaciones
      const onFileChange = () => {
        const ordenInputs = Array.from(document.querySelectorAll('.orden-file'));
        const firstOrdenFile = ordenInputs.map(i => i.files[0]).filter(Boolean)[0] || null;

        nDepositoInput.removeAttribute('data-extracted');
        nDepositoInput.removeAttribute('data-user-chosen');
        nDepositNoteDiv.innerHTML = "";

        if (firstOrdenFile && firstOrdenFile.name) {
          // validar que empiece con FIRMA_
          if (!/^FIRMA_/i.test(firstOrdenFile.name)) {
            nDepositNoteDiv.innerHTML = `<small style="color:crimson;">ATENCIÓN: la ORDEN debe empezar con <strong>FIRMA_</strong>. Archivo detectado: <strong>${escapeHtml(firstOrdenFile.name)}</strong></small>`;
            return;
          }
          const found = getDepositFromOrderFilename(firstOrdenFile.name);
          if (found) {
            if (!nDepositoInput.value || nDepositoInput.value.trim() === '') {
              nDepositoInput.value = found;
              nDepositNoteDiv.innerHTML = `<small>Autoextraído desde ORDEN: <strong>${found}</strong></small>`;
              nDepositoInput.dispatchEvent(new Event('change', { bubbles: true }));
            } else {
              const current = nDepositoInput.value.trim();
              if (current !== found) {
                nDepositoInput.setAttribute('data-extracted', found);
                nDepositNoteDiv.innerHTML = `
                  <small style="color:darkorange;">
                    Se detectó <strong>${found}</strong> en el nombre del archivo de ORDEN, pero el campo contiene <strong>${escapeHtml(current)}</strong>.
                  </small>
                  <div style="margin-top:8px;">
                    <button type="button" id="usarExtraidoBtn" class="chat-action-btn" style="margin-right:8px;">Usar ${escapeHtml(found)}</button>
                    <button type="button" id="mantenerBtn" class="chat-action-btn btn-gris">Mantener ${escapeHtml(current)}</button>
                  </div>
                `;
                const usarBtn = document.getElementById('usarExtraidoBtn');
                const mantBtn = document.getElementById('mantenerBtn');
                const onUsar = (ev) => {
                  ev.preventDefault(); ev.stopPropagation();
                  nDepositoInput.value = found;
                  nDepositoInput.setAttribute('data-user-chosen', 'extracted');
                  nDepositoInput.removeAttribute('data-extracted');
                  nDepositNoteDiv.innerHTML = `<small>Se reemplazó el campo por el valor detectado: <strong>${escapeHtml(found)}</strong></small>`;
                  nDepositoInput.dispatchEvent(new Event('change', { bubbles: true }));
                };
                const onMant = (ev) => {
                  ev.preventDefault(); ev.stopPropagation();
                  nDepositoInput.setAttribute('data-user-chosen', 'kept');
                  nDepositoInput.removeAttribute('data-extracted');
                  nDepositNoteDiv.innerHTML = `<small>Mantenido: <strong>${escapeHtml(current)}</strong></small>`;
                  nDepositoInput.dispatchEvent(new Event('change', { bubbles: true }));
                };
                if (usarBtn) usarBtn.addEventListener('click', onUsar);
                if (mantBtn) mantBtn.addEventListener('click', onMant);
              } else {
                nDepositNoteDiv.innerHTML = `<small>Detectado y coincide con el campo: <strong>${escapeHtml(found)}</strong></small>`;
              }
            }
          } else {
            nDepositoInput.removeAttribute('data-extracted');
            nDepositNoteDiv.innerHTML = `<small style="color:crimson;">No se pudo extraer número de depósito del nombre de ORDEN. Debe comenzar con <strong>FIRMA_&lt;NRO&gt;_</strong></small>`;
          }
        } else {
          nDepositoInput.removeAttribute('data-extracted');
          nDepositNoteDiv.innerHTML = "";
        }
      };

      // attach change listener to orden container (delegation)
      ordenesContainer.addEventListener('change', (ev) => {
        if (ev.target && ev.target.matches('.orden-file')) onFileChange();
      });

      // onCancel
      const onCancel = () => {
        ordenesContainer.removeEventListener('change', onFileChange);
        if (form) form.removeEventListener('submit', onSubmit);
        if (modalEl) modalEl.removeEventListener('click', onModalOutsideClick);
        if (modalBodyEl) modalBodyEl.removeEventListener('click', blockBubbling);
        cerrarModal();
      };
      cancelBtn.addEventListener('click', onCancel);

      // click outside closes
      const onModalOutsideClick = (ev) => {
        if (ev.target === modalEl) onCancel();
      };
      modalEl.addEventListener('click', onModalOutsideClick);

      // --- SUBMIT ---
      async function onSubmit(e) {
        e.preventDefault();
        statusDiv.innerHTML = "";
        // collect files
        const ordenInputs = Array.from(document.querySelectorAll('.orden-file'));
        const ordenFiles = ordenInputs.map(i => i.files[0]).filter(Boolean);
        const resolInputs = Array.from(document.querySelectorAll('.resol-file'));
        const resolFiles = resolInputs.map(i => i.files[0]).filter(Boolean);

        if (!ordenFiles.length && !resolFiles.length) {
          return Swal.fire("Error", "Selecciona al menos un PDF de orden o una resolución.", "error");
        }

        // --- CAMBIO: exige resolucion al menos 1 ---
        if (!resolFiles.length) {
          return Swal.fire("Error", "Es obligatorio subir al menos una RESOLUCIÓN junto con la(s) orden(es).", "error");
        }

        const maxMB = 12; const maxBytes = maxMB * 1024 * 1024;
        for (const f of [...ordenFiles, ...resolFiles].filter(Boolean)) {
          if (f.size > maxBytes) return Swal.fire("Error", `Archivo "${f.name}" supera ${maxMB} MB.`, "error");
          if (f.type !== "application/pdf") return Swal.fire("Error", `Archivo "${f.name}" no es PDF.`, "error");
        }

        // Validación de mapeo: si hay más de 1 resolución, debe coincidir con número de órdenes
        if (resolFiles.length > 1 && resolFiles.length !== ordenFiles.length) {
          return Swal.fire("Error", `Si subes varias resoluciones, deben ser exactamente ${ordenFiles.length} (una por orden). Actualmente subiste ${resolFiles.length}.`, "error");
        }

        // --- CAMBIO: validar nombres de orden comiencen con FIRMA_ y extraer nro ---
        for (const f of ordenFiles) {
          if (!/^FIRMA_/i.test(f.name)) {
            return Swal.fire("Error", `El archivo de ORDEN "${f.name}" debe comenzar con "FIRMA_".`, "error");
          }
        }

        // determinar n_deposito solo desde ORDEN
        let nVal = (nDepositoInput.value || '').trim().replace(/\D/g,'');
        if (!nVal) {
          const fromFiles = ordenFiles.length ? getDepositFromOrderFilename(ordenFiles[0].name) : '';
          nVal = (fromFiles || (chatNDepositoActual ? String(chatNDepositoActual).replace(/\D/g,'') : '')).replace(/\D/g,'');
          if (fromFiles && (!nDepositoInput.value || nDepositoInput.value.trim() === '')) {
            nDepositoInput.value = fromFiles;
            nDepositoInput.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }

        if (nVal && !/^\d{13}$/.test(nVal)) {
          return Swal.fire("Error", `El número de depósito debe tener exactamente 13 dígitos. Valor detectado: "${nVal}" (${nVal.length} dígitos).`, "error");
        }

        // determinar idToSend (origen para duplicar)
        let idToSend = null;
        try {
          if (typeof idDep !== 'undefined' && idDep) idToSend = idDep;
          if (!idToSend && chatDepositoActual) idToSend = chatDepositoActual;
          if (!idToSend && nVal) idToSend = findIdDepByDisplayedN(nVal);
        } catch (err) { console.warn("No se pudo determinar idToSend automáticamente:", err); }

        const fd = new FormData();
        // agregar ordenes (multiples)
        ordenFiles.forEach(f => fd.append('orden_pdf[]', f));

        // resoluciones: dos posibilidades
        if (resolFiles.length === 1) {
          fd.append('resolucion_pdf', resolFiles[0]);
          fd.append('resolucion_shared', '1');
        } else if (resolFiles.length === ordenFiles.length && resolFiles.length > 0) {
          resolFiles.forEach(f => fd.append('resolucion_pdf[]', f));
        }

        if (nVal) fd.append('n_deposito', nVal);
        if (idToSend) fd.append('id_deposito', String(idToSend));

        if (ordenFiles.length > 1 && idToSend) {
          fd.append('duplicate_mode', 'copy_existing');
          fd.append('duplicate_from_id', String(idToSend));
        }

        submitBtn.disabled = true;
        submitBtn.textContent = "Subiendo...";
        statusDiv.innerHTML = "<small>Cargando archivos…</small>";

        try {
          const resp = await fetch("../code_back/back_deposito_subir_pdf.php", { method: "POST", body: fd });
          const text = await resp.text();
          let j;
          try { j = text ? JSON.parse(text) : null; } catch (parseErr) {
            console.error("Respuesta no-JSON:", text);
            submitBtn.disabled = false;
            submitBtn.textContent = "Enviar archivos";
            statusDiv.innerHTML = "";
            abrirModal("Respuesta del servidor (no JSON)", `<div style="max-height:400px; overflow:auto; text-align:left;"><pre style="white-space:pre-wrap;">${escapeHtml(text)}</pre></div>`);
            return;
          }

          submitBtn.disabled = false;
          submitBtn.textContent = "Enviar archivos";
          statusDiv.innerHTML = "";

          if (!j || !j.success) {
            if (j && j.candidates && j.candidates.length > 0) {
              let opts = j.candidates.map(c => `<button class="chat-action-btn" data-id="${c.id_deposito}">Fila ${c.id_deposito} - Expediente: ${c.n_expediente || 'N/A'}</button>`).join('<br>');
              abrirModal("Seleccionar fila pendiente", `<p>Se encontraron varias filas pendientes. Selecciona la que corresponde:</p><div>${opts}</div>`);
              document.getElementById("modal-body").addEventListener('click', function modalClick(ev) {
                const btn = ev.target.closest('button[data-id]');
                if (!btn) return;
                const chosenId = btn.getAttribute('data-id');
                document.getElementById("modal-body").removeEventListener('click', modalClick);
                cerrarModal();
                (async () => {
                  const bodyStr = `id_deposito=${encodeURIComponent(chosenId)}&nuevo_deposito=${encodeURIComponent(nVal)}&n_deposito=${encodeURIComponent(nVal)}`;
                  const r = await fetch("../code_back/back_deposito_registrar.php", {
                    method: "POST",
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: bodyStr
                  });
                  const rj = await r.json();
                  if (rj && rj.success) {
                    Swal.fire('✅ Actualizado', 'Número de depósito actualizado correctamente.', 'success').then(() => location.reload());
                  } else {
                    Swal.fire('Error', (rj && (rj.msg || rj.message)) || 'No se pudo actualizar.', 'error');
                  }
                })();
              });
              return;
            }

            cerrarModal();
            return Swal.fire("❌ Error al subir", (j && (j.msg || j.message)) || "Error desconocido", "error");
          }

          // flujo de éxito: si backend indica que ya registró nuevas filas:
          if (j.registered === true) {
            cerrarModal();
            cerrarModalChat();
            if (j.beneficiario && j.beneficiario.telefono) {
              const { link } = buildWaLinkFromData(j.beneficiario);
              Swal.fire({
                icon: 'success',
                title: '✅ Archivos registrados correctamente',
                text: '¿Deseas notificar al beneficiario por WhatsApp?',
                showCancelButton: true,
                confirmButtonText: '📲 Sí, notificar',
                cancelButtonText: 'Cerrar'
              }).then(res => {
                if (res.isConfirmed) window.open(link, '_blank'); else location.reload();
              });
            } else {
              Swal.fire('✅ Archivos registrados', 'Registro completado.', 'success').then(() => location.reload());
            }
            return;
          }

          if (j.updated === true) {
            cerrarModal();
            cerrarModalChat();
            Swal.fire('✅ Actualizado', 'Registro actualizado correctamente.', 'success').then(() => location.reload());
            return;
          }

          // fallback
          cerrarModal();
          cerrarModalChat();
          return Swal.fire("OK", j.msg || "Operación completada.", "success");

        } catch (err) {
          console.error(err);
          submitBtn.disabled = false;
          submitBtn.textContent = "Enviar archivos";
          statusDiv.innerHTML = "";
          cerrarModal();
          Swal.fire("❌ Error de red", err.message, "error");
        }
      } // onSubmit

      form.addEventListener('submit', onSubmit);
    }; // fin onClickBtnPdf

    btnPdf.addEventListener('click', onClickBtnPdf);
    cont.appendChild(btnPdf);
  }

  // ----------------- Programar Fecha Recojo (solo secretario y solo cuando state in GrupoA) -----------------
  if (typeof rolActual !== 'undefined' && rolActual === 3 && stateAs3) {
    const btnRecojo = document.createElement("button");
    btnRecojo.textContent = "Programar Fecha Recojo";
    btnRecojo.className   = "chat-action-btn btn-amarillo";
    btnRecojo.style.marginLeft = "8px";

    btnRecojo.onclick = () => {
      abrirModal("Programar Fecha de Recojo", `
        <div style="text-align:left;">
          <label style="font-weight:600;">Fecha y hora de recojo</label><br>
          <input id="input-fecha-recojo" type="datetime-local" style="width:100%; padding:6px; margin-top:6px;">
          <div style="margin-top:12px; text-align:right;">
            <button id="confirm-fecha-recojo" class="chat-action-btn">Confirmar</button>
            </div>
        </div>
      `);

      // cerrar modal chat para que se vea mejor la ventanita
      cerrarModalChat();

      const confirmBtn = document.getElementById('confirm-fecha-recojo');
      const cancelBtn  = document.getElementById('cancel-fecha-recojo');
      const inputEl    = document.getElementById('input-fecha-recojo');

      const cleanup = () => {
        try {
          confirmBtn && confirmBtn.removeEventListener('click', onConfirm);
          cancelBtn  && cancelBtn.removeEventListener('click', onCancel);
        } catch(e){}
        cerrarModal();
      };

      const onCancel = () => cleanup();

      const onConfirm = async () => {
        const val = inputEl ? inputEl.value : '';
        if (!val) return Swal.fire('Atención', 'Selecciona fecha y hora.', 'warning');
        const res = await Swal.fire({
          title: 'Confirmar fecha de recojo',
          html: `Programar recojo para: <strong>${(new Date(val)).toLocaleString()}</strong>?`,
          showCancelButton: true,
          confirmButtonText: 'Sí, programar'
        });
        if (!res.isConfirmed) return;

        // Determinar id a enviar
        let idToSend = chatDepositoActual;
        if (!idToSend || isNaN(parseInt(idToSend,10))) {
          if (chatNDepositoActual) {
            idToSend = findIdDepByDisplayedN(chatNDepositoActual);
          }
        }
        if (!idToSend) {
          Swal.fire('Error', 'No se pudo determinar id_deposito para este depósito. Asegúrate que la fila tenga data-iddep.', 'error');
          return;
        }

        try {
          const fd = new FormData();
          fd.append('id_deposito', String(idToSend));
          fd.append('fecha_recojo', val);

          /* --- CAMBIO: si el estado actual es 5 o 6, forzar que en backend se registre COMO 8 (reprogramado) --- */
          if ([5,6].includes(parseInt(state,10))) {
            fd.append('estado_nuevo', '8'); // backend debe respetar este valor
            fd.append('note', 'Reprogramación: estado 8 (recojo reprogramado)');
          } else {
            // opcional: si no se necesita paso extra el backend puede decidir estado 5
            fd.append('note', 'Fecha de recojo programada por secretario');
          }
          /* --- FIN CAMBIO --- */

          // rutas a intentar
          const tryUrls = [
            "../code_back/back_deposito_recojo.php",
            "/Sistemas/SISDEJU/code_back/back_deposito_recojo.php",
            "code_back/back_deposito_recojo.php"
          ];

          const { resp, url } = await fetchWithFallback(tryUrls, { method: 'POST', body: fd });

          const text = await resp.text();
          let j;
          try {
            j = text ? JSON.parse(text) : null;
          } catch (parseErr) {
            // mostrar HTML / texto para debug
            abrirModal("Respuesta del servidor (no JSON)", `<div style="max-height:400px; overflow:auto; text-align:left;"><pre style="white-space:pre-wrap;">${escapeHtml(text)}</pre></div>`);
            return;
          }

          if (!j || !j.ok) {
            return Swal.fire('Error', j && (j.msg || j.message) ? (j.msg || j.message) : 'No se programó', 'error');
          }

          // Cerrar modal local
          cleanup();

          // Añadir entrada al historial del chat (visual)
          const historialEl = document.getElementById('chat-historial');
          if (historialEl) {
            const div = document.createElement('div');
            div.style.padding = '6px 8px';
            div.style.borderBottom = '1px solid #eee';
            const fechaText = j.fecha_recojo ? (new Date(j.fecha_recojo)).toLocaleString() : (new Date(val)).toLocaleString();
            div.innerHTML = `<strong>FECHA RECOJO</strong> — ${fechaText}<br><small>Recojo programado por secretario</small>`;
            historialEl.appendChild(div);
            historialEl.scrollTop = historialEl.scrollHeight;
          }

          // Actualizar fila en tabla: buscar tr[data-iddep]
          try {
            let tr = document.querySelector(`#tabla-depositos tbody tr[data-iddep="${idToSend}"]`);
            if (!tr) {
              tr = Array.from(document.querySelectorAll('#tabla-depositos tbody tr')).find(r => {
                return r.dataset.id === String(idToSend) || r.dataset.id_deposito === String(idToSend);
              });
            }
            if (tr) {
              const nuevoEstado = j.estado_nuevo || ( ([5,6].includes(parseInt(state,10))) ? 8 : 5 );
              tr.setAttribute('data-estado', String(nuevoEstado));
              const estadoCell = tr.querySelector('td.estado-col');
              if (estadoCell) {
                estadoCell.setAttribute('data-estado', String(nuevoEstado));
                estadoCell.textContent = (j.nombre_estado || (nuevoEstado === 8 ? 'Reprogramado' : 'Fecha Recojo'));
                estadoCell.style.transition = 'background-color 0.3s';
              }
              const fechaCell = tr.querySelector('td[data-col="fecha-recojo"]');
              if (fechaCell) {
                const d = j.fecha_recojo ? new Date(j.fecha_recojo) : new Date(val);
                fechaCell.textContent = d ? d.toLocaleString() : (j.fecha_recojo || val);
              }
            } else {
              console.warn('No se encontró fila para id_deposito', idToSend);
            }
          } catch (uerr) {
            console.error('Error actualizando fila tras programar recojo:', uerr);
          }

          Swal.fire('OK', 'Fecha de recojo programada.', 'success').then(() => location.reload());

        } catch (err) {
          console.error('Error programarFechaRecojo:', err);
          Swal.fire('Error', err.message || 'Error de red', 'error');
        }
      }; // onConfirm

      confirmBtn && confirmBtn.addEventListener('click', onConfirm);
      cancelBtn && cancelBtn.addEventListener('click', onCancel);
    };

    cont.appendChild(btnRecojo);
  }

  // Cancelar registro + borrar PDF (rol 3, estado en GrupoB)
  if (typeof rolActual !== 'undefined' && rolActual === 3 && stateAsGroupB) {
    const btnCancel = document.createElement("button");
    btnCancel.textContent = "Cancelar Registro de Orden";
    btnCancel.className   = "chat-action-btn btn-rojo";
    if (idDep) {
      btnCancel.onclick = () => cancelarRegistro(idDep);
    } else {
      btnCancel.disabled = true;
      btnCancel.title = "Falta número de depósito (id_deposito)";
    }
    cont.appendChild(btnCancel);
  }

  // Entregar orden (roles 2, 4, 5 con estado en GrupoB)
  if (typeof rolActual !== 'undefined' && [2, 4, 5].includes(rolActual) && stateAsGroupB) {
    const btnEnt = document.createElement("button");
    btnEnt.textContent = "Entregar Orden al Usuario";
    btnEnt.className   = "chat-action-btn btn-azul-oscuro";
    if (idDep) {
      btnEnt.onclick = () => confirmarEntregar(idDep);
    } else {
      btnEnt.disabled = true;
      btnEnt.title = "Falta número de depósito (id_deposito)";
    }
    cont.appendChild(btnEnt);
  }

} // fin injectChatActions




// ------------------ Enviar mensaje ------------------
function enviarMensaje() {
  // permisos: GrupoA (3,5,6,8,9) pueden enviar; GrupoB (2,7) solo si rolActual === 2
  const allowedToSend = [3,5,6,8,9].includes(estadoActualChat) ||
                        ([2,7].includes(estadoActualChat) && typeof rolActual !== 'undefined' && rolActual === 2);
  if (!allowedToSend) return;

  if (!chatDepositoActual || String(chatDepositoActual).trim() === '') {
    return Swal.fire('Atención', 'No se pudo determinar id_deposito para enviar el comentario.', 'warning');
  }
  const idParsed = parseInt(chatDepositoActual, 10);
  if (!Number.isFinite(idParsed) || idParsed <= 0) {
    return Swal.fire('Atención', 'id_deposito inválido.', 'warning');
  }

  const cEl = document.getElementById("chat-comentario");
  const c = cEl ? cEl.value.trim() : '';
  if (!c) return;

  const urls = tryUrlsFor('add_historial');
  const body = `id_deposito=${encodeURIComponent(idParsed)}&usuario=${encodeURIComponent(usuarioActual || '')}&comentario=${encodeURIComponent(c)}`;

  fetchWithFallback(urls, {
    method:  "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body
  })
  .then(async ({ resp }) => {
    const txt = await resp.text();
    let j;
    try { j = txt ? JSON.parse(txt) : null; } catch(e){ j = null; }

    if (j && (j.success || j.ok)) {
      const newMsg = j.data ? j.data : (Array.isArray(j.historial) ? j.historial[j.historial.length-1] : null);
      if (newMsg) {
        appendSingleMessageToChat({
          documento_usuario: newMsg.documento_usuario || usuarioActual,
          comentario_deposito: newMsg.comentario_deposito || newMsg.comentario || c,
          fecha_historial_deposito: newMsg.fecha_historial_deposito || newMsg.fecha || new Date().toISOString()
        });
      } else {
        await cargarHistorial(idParsed);
      }
      if (cEl) cEl.value = "";
      window.lastTimestampByDep[keyFor(idParsed)] = new Date().toISOString();
    } else {
      if (j && j.ok && j.data) {
        appendSingleMessageToChat(j.data);
        if (cEl) cEl.value = "";
        window.lastTimestampByDep[keyFor(idParsed)] = j.data.fecha_historial_deposito || new Date().toISOString();
      } else {
        await cargarHistorial(idParsed);
        Swal.fire("❌ Error", (j && (j.msg || j.message)) || "No se pudo enviar el mensaje.", "error");
      }
    }
  })
  .catch(err => {
    console.error(err);
    Swal.fire("❌ Error de red", err.message || 'Error', "error");
  });
}


// ------------------ Confirm / acciones varias (sin cambios importantes) ------------------
function confirmarRegistrar(dep) {
  Swal.fire({
    title:             '¿Registrar orden?',
    text:              'Esto cambiará el estado y cerrará el chat.',
    icon:              'question',
    showCancelButton:  true,
    confirmButtonText: 'Sí, registrar',
    cancelButtonText:  'Cancelar'
  }).then(r => {
    if (!r.isConfirmed) return;
    fetch("../code_back/back_deposito_registrar.php", {
      method:  "POST",
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    `n_deposito=${encodeURIComponent(dep)}`
    })
    .then(r2 => r2.json())
    .then(j => {
      Swal.fire(j.success ? 'Registrado' : 'Error', j.msg || j.message, j.success ? 'success' : 'error')
        .then(() => { if (j.success) location.reload(); });
    });
  });
}

function confirmarEntregar(dep) {
  Swal.fire({
    title:             '¿Entregar orden?',
    text:              'Esto finalizará el proceso y cerrará el chat.',
    icon:              'warning',
    showCancelButton:  true,
    confirmButtonText: 'Sí, entregar',
    cancelButtonText:  'Cancelar'
  }).then(r => {
    if (!r.isConfirmed) return;
    fetch("../code_back/back_deposito_entregar.php", {
      method:  "POST",
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    `id_deposito=${encodeURIComponent(dep)}`
    })
    .then(r2 => r2.json())
    .then(j => {
      Swal.fire(j.success ? 'Entregado' : 'Error', j.msg || j.message, j.success ? 'success' : 'error')
        .then(() => { if (j.success) location.reload(); });
    });
  });
}

function cancelarRegistro(dep) {
  Swal.fire({
    title:             '¿Cancelar registro de orden?',
    text:              'Esto eliminará el PDF, lo dejará en blanco y devolverá el estado a Pendiente.',
    icon:              'warning',
    showCancelButton:  true,
    confirmButtonText: 'Sí, cancelar',
    cancelButtonText:  'No'
  }).then(r => {
    if (!r.isConfirmed) return;

    fetch("../code_back/back_deposito_cancelar_pdf.php", {
      method:  "POST",
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    `id_deposito=${encodeURIComponent(dep)}`
    })
    .then(res => res.json())
    .then(j => {
      if (!j.success) return Swal.fire("❌ Error", j.msg || "Error al borrar el PDF.", "error");
      return fetch("../code_back/back_deposito_cancelar_registro.php", {
        method:  "POST",
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    `id_deposito=${encodeURIComponent(dep)}`
      });
    })
    .then(res2 => res2.json())
    .then(j2 => {
      if (j2.success) {
        cerrarModalChat();
        Swal.fire("✅ Cancelado", j2.msg || "Registro cancelado correctamente.", "success")
          .then(() => location.reload());
      } else {
        Swal.fire("❌ Error", j2.msg || "No se pudo revertir el estado.", "error");
      }
    })
    .catch(err => {
      console.error(err);
      Swal.fire("❌ Error de red", err.message, "error");
    });
  });
}

function mostrarModalNotificacion(dep) {
  abrirModal("Notificar Depósito", `
    <p>¿Estás seguro de notificar el depósito <strong>${dep}</strong>?</p>
    <div style="margin-top:15px;">
      <button class="chat-action-btn" onclick="confirmarNotificacion('${dep}')">Confirmar</button>
    </div>
  `);
}

function confirmarNotificacion(dep) {
  fetch("../code_back/back_deposito_notificar.php", {
    method:  "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body:    `n_deposito=${encodeURIComponent(dep)}`
  })
  .then(r => r.json())
  .then(j => {
    alert(j.msg || j.message || 'OK');
    if (j.success) location.reload();
    else cerrarModal();
  });
}

function filtrarPorEstado() {
  const est = document.getElementById("filtroEstado").value;
  document.querySelectorAll("#tabla-depositos tbody tr").forEach(tr => {
    const data = parseInt(tr.getAttribute("data-estado"), 10);
    tr.style.display = (est === "todos" || (est === "pendientes" && data === 3)) ? "" : "none";
  });
}

function buildWaLinkFromData(data) {
  let tel = (data.telefono || '').replace(/\D/g, '');
  if (tel.startsWith('0')) tel = tel.slice(1);
  const nombre = `${data.nombre || ''} ${data.apellido || ''}`.trim();
  const exp    = data.expediente || '';
  let mensaje;
  if (parseInt(data.estado) === 2) {
    mensaje = `Hola ${nombre}, su expediente ${exp} ya tiene orden de pago lista. Puede recogerla en el horario de 8:30am - 1pm y 3pm - 4:45pm.`;
  } else if (parseInt(data.estado) === 5) {
    if (data.fecha_recojo) {
      mensaje = `Hola ${nombre}, su expediente ${exp} tiene fecha de recojo el ${data.fecha_recojo}.  Puede acercarse otro día posterior a este en el horario de 8:30am - 1pm y 3pm - 4:45pm.`;
    } else {
      mensaje = `Hola ${nombre}, su expediente ${exp} acerquese en la fecha indicada. Puede recogerla en el horario de 8:30am - 1pm y 3pm - 4:45pm.`;
    }
  } else {
    mensaje = `Hola ${nombre}, ya puede acercarse a recoger su orden de pago de su expediente ${exp} en el horario de 8:30am - 1pm y 3pm - 4:45pm.`;
  }

  const link = `https://wa.me/51${tel}?text=${encodeURIComponent(mensaje)}`;
  return { link, mensaje };
}

// ----------------- abrirEdicionDeposito - VERSIÓN COMPLETA CON BÚSQUEDA DE BENEFICIARIO -----------------
async function abrirEdicionDeposito(icon) {
  const el = icon;
  const tr = el.closest('tr');

  const idDep_from_attr = el.dataset.iddep || el.dataset.idDep || el.dataset.id || (tr ? tr.dataset.iddep : null);
  
  let idDep = idDep_from_attr || null;

  if (!idDep || idDep === '') {
    return Swal.fire('Atención', 'No se encontró identificador del depósito.', 'warning');
  }

  // Mostrar loading
  Swal.fire({
    title: 'Cargando datos del depósito...',
    allowOutsideClick: false,
    didOpen: () => { Swal.showLoading(); }
  });

  try {
    // Obtener datos completos del depósito
    const formData = new FormData();
    formData.append('id_deposito', idDep);
    
    const response = await fetch('../code_back/back_deposito_get_full.php', {
      method: 'POST',
      body: formData
    });
    
    const data = await response.json();
    
    if (!data.success) {
      throw new Error(data.msg || 'Error al cargar datos');
    }

    const dep = data.deposito;
    const secretarios = data.secretarios || [];
    const beneficiarios = data.beneficiarios || [];
    const rolUsuario = data.rol_usuario;
    
    // Separar número de expediente en partes
    const expParts = (dep.n_expediente || '').split('-');
    const exp1 = expParts[0] || '';
    const exp2 = expParts[1] || '';
    const exp3 = expParts[2] || '';

    // Determinar si mostrar fecha de recojo (solo si ya tiene una asignada)
    const mostrarFechaRecojo = dep.fecha_recojo_deposito && dep.fecha_recojo_deposito.trim() !== '';
    
    // Solo secretarios (rol 3) pueden editar la fecha de recojo
    const puedeEditarFechaRecojo = mostrarFechaRecojo && (rolUsuario === 3 || rolUsuario === 1);

    // Construir HTML del formulario completo
    const htmlForm = `
      <div style="text-align:left; max-width:700px; margin:0 auto;">
        
        <!-- Buscador de Beneficiario -->
        <div style="margin-bottom:20px;">
          <label style="display:block; font-weight:600; margin-bottom:5px;">Beneficiario *</label>
          <input type="text" id="edit_buscar_beneficiario" 
                 value="${escapeHtml(dep.documento_beneficiario || '')} - ${escapeHtml(dep.nombre_beneficiario || '')} ${escapeHtml(dep.apellido_beneficiario || '')}"
                 placeholder="Buscar por DNI o nombre..."
                 style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;"
                 autocomplete="off">
          
          <!-- Lista de coincidencias (dropdown) -->
          <div id="edit_lista_beneficiarios" 
               style="position:relative; width:100%; max-height:200px; overflow:auto; 
                      border:1px solid #ddd; background:#fff; display:none; 
                      border-radius:4px; margin-top:2px; box-shadow:0 2px 6px rgba(0,0,0,0.1);">
          </div>
          
          <!-- Beneficiario seleccionado (info) -->
          <div id="edit_beneficiario_info" style="margin-top:8px; padding:10px; background:#f9f9f9; 
               border-radius:6px; display:${dep.documento_beneficiario ? 'block' : 'none'};">
            <strong id="edit_benef_nombre">${escapeHtml(dep.nombre_beneficiario || '')} ${escapeHtml(dep.apellido_beneficiario || '')}</strong><br>
            <small style="color:#666;">
              DNI: <span id="edit_benef_dni">${escapeHtml(dep.documento_beneficiario || '')}</span> | 
              Tel: <span id="edit_benef_tel">${escapeHtml(dep.telefono_beneficiario || '--')}</span>
            </small>
          </div>
          
          <input type="hidden" id="edit_beneficiario_documento" value="${escapeHtml(dep.documento_beneficiario || '')}">
        </div>

        <!-- Número de Expediente -->
        <div style="margin-bottom:15px;">
          <label style="display:block; font-weight:600; margin-bottom:5px;">Nº de Expediente *</label>
          <div style="display:flex; gap:5px; align-items:center;">
            <input type="text" id="edit_exp1" value="${escapeHtml(exp1)}" maxlength="5" 
                   style="width:80px; padding:8px; border:1px solid #ddd; border-radius:4px;" required>
            <span>-</span>
            <input type="text" id="edit_exp2" value="${escapeHtml(exp2)}" maxlength="4" 
                   style="width:70px; padding:8px; border:1px solid #ddd; border-radius:4px;" required>
            <span>-</span>
            <input type="text" id="edit_exp3" value="${escapeHtml(exp3)}" maxlength="2" 
                   style="width:60px; padding:8px; border:1px solid #ddd; border-radius:4px;" required>
          </div>
        </div>

        <!-- Número de Depósito -->
          <input type="hidden" id="edit_deposito" value="${escapeHtml(dep.n_deposito || '')}">

        <!-- Tipo Juzgado y Juzgado en la misma fila; Secretario en fila separada -->
        <div style="margin-bottom:15px; display:flex; gap:8px; align-items:center;">
          <div style="flex:1;">
            <label style="display:block; font-weight:600; margin-bottom:5px;">Tipo de Juzgado</label>
            <select id="edit_tipo_juzgado" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
              <option value="" selected>Seleccione Tipo</option>
            </select>
          </div>
          <div style="flex:2;">
            <label style="display:block; font-weight:600; margin-bottom:5px;">Juzgado</label>
            <select id="edit_juzgado" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
              <option value="" selected>Seleccione un Juzgado</option>
            </select>
          </div>
        </div>

        <div style="margin-bottom:15px;">
          <label style="display:block; font-weight:600; margin-bottom:5px;">Secretario *</label>
          <select id="edit_secretario" required
                  style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
            <option value="">Seleccione un secretario</option>
          </select>
        </div>

        ${puedeEditarFechaRecojo ? `
        <!-- Fecha de Recojo (solo si ya tiene una asignada y es secretario/admin) -->
        <div style="margin-bottom:15px;">
          <label style="display:block; font-weight:600; margin-bottom:5px;">Fecha de Recojo</label>
          <input type="datetime-local" id="edit_fecha_recojo" 
                 value="${dep.fecha_recojo_deposito ? dep.fecha_recojo_deposito.replace(' ', 'T').substring(0, 16) : ''}"
                 style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
          <small style="display:block; color:#666; margin-top:4px;">Puede modificar la fecha de recojo programada.</small>
        </div>
        ` : ''}

        <!-- Campo de Observación (solo MAU rol 2 y Admin rol 1) -->
        ${(rolUsuario === 1 || rolUsuario === 2) ? `
        <div style="margin-bottom:15px;">
          <label style="display:block; font-weight:600; margin-bottom:5px;">Observación (opcional)</label>
          <textarea id="edit_observacion" rows="4" 
                    placeholder="Agregue cualquier observación sobre este depósito..."
                    style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px; resize:vertical;">${escapeHtml(dep.observacion || '')}</textarea>
          <small style="display:block; color:#666; margin-top:4px;">Este campo permite agregar notas o comentarios adicionales sobre el depósito.</small>
        </div>
        ` : (dep.observacion ? `
        <!-- Observación (solo lectura para otros roles) -->
        <div style="margin-bottom:15px;">
          <label style="display:block; font-weight:600; margin-bottom:5px;">Observación</label>
          <div style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px; background:#f5f5f5; min-height:80px;">
            ${escapeHtml(dep.observacion)}
          </div>
          <small style="display:block; color:#666; margin-top:4px;">Solo MAU y Administradores pueden editar este campo.</small>
        </div>
        ` : '')}

        <small style="display:block; margin-top:15px; color:#666;">* Campos obligatorios</small>
      </div>
    `;

    // Mostrar modal con el formulario
    const modalResult = await Swal.fire({
      title: `Editar Depósito`,
      html: htmlForm,
      width: 750,
      showCancelButton: true,
      confirmButtonText: 'Guardar cambios',
      cancelButtonText: 'Cancelar',
      didOpen: () => {
        // Configurar buscador de beneficiarios
        const inputBuscar = document.getElementById('edit_buscar_beneficiario');
        const listaBenef = document.getElementById('edit_lista_beneficiarios');
        const benefInfo = document.getElementById('edit_beneficiario_info');
        const benefDocHidden = document.getElementById('edit_beneficiario_documento');
        
        let beneficiariosData = beneficiarios;
        let beneficiarioSeleccionado = dep.documento_beneficiario || null;

        // Función para filtrar y mostrar beneficiarios
        function filtrarBeneficiarios(texto) {
          const q = texto.trim().toLowerCase();
          
          if (!q || q.length < 2) {
            listaBenef.style.display = 'none';
            listaBenef.innerHTML = '';
            return;
          }

          const matches = beneficiariosData.filter(b => {
            const dni = (b.documento || '').toLowerCase();
            const nombre = (b.nombre_completo || '').toLowerCase();
            return dni.includes(q) || nombre.includes(q);
          });

          if (matches.length === 0) {
            listaBenef.style.display = 'none';
            listaBenef.innerHTML = '';
            return;
          }

          listaBenef.innerHTML = '';
          listaBenef.style.display = 'block';

          matches.forEach(b => {
            const item = document.createElement('div');
            item.style.cssText = 'padding:10px; cursor:pointer; border-bottom:1px solid #eee;';
            item.innerHTML = `
              <strong>${escapeHtml(b.documento)}</strong> - ${escapeHtml(b.nombre_completo)}<br>
              <small style="color:#666;">Tel: ${escapeHtml(b.telefono || '--')}</small>
            `;
            
            item.addEventListener('mouseenter', () => item.style.background = '#f0f0f0');
            item.addEventListener('mouseleave', () => item.style.background = '#fff');
            item.addEventListener('click', () => {
              // Seleccionar beneficiario
              inputBuscar.value = `${b.documento} - ${b.nombre_completo}`;
              benefDocHidden.value = b.documento;
              beneficiarioSeleccionado = b.documento;
              
              // Actualizar info
              document.getElementById('edit_benef_nombre').textContent = b.nombre_completo;
              document.getElementById('edit_benef_dni').textContent = b.documento;
              document.getElementById('edit_benef_tel').textContent = b.telefono || '--';
              benefInfo.style.display = 'block';
              
              // Ocultar lista
              listaBenef.style.display = 'none';
            });
            
            listaBenef.appendChild(item);
          });
        }

        // Event listener para el buscador
        inputBuscar.addEventListener('input', (e) => {
          filtrarBeneficiarios(e.target.value);
        });

        // Cerrar lista al hacer clic fuera (usamos handler nombrado para evitar acumulación)
        function __closingClickHandlerForBenef(e) {
          if (!e.target.closest('#edit_buscar_beneficiario') && !e.target.closest('#edit_lista_beneficiarios')) {
            listaBenef.style.display = 'none';
          }
        }
        // remover si ya existía (por seguridad) y volver a añadir
        try { document.removeEventListener('click', __closingClickHandlerForBenef); } catch(_) {}
        document.addEventListener('click', __closingClickHandlerForBenef);

        // --- POBLAR SELECTS DE JUZGADOS / TIPOS / SECRETARIOS ---
        const tiposSel = document.getElementById('edit_tipo_juzgado');
        const juzgadoSel = document.getElementById('edit_juzgado');
        const secretarioSel = document.getElementById('edit_secretario');

        // datos enviados por backend: `juzgados` y `secretarios` (lista completa)
        const juzgadosData = (data.juzgados) ? data.juzgados : [];
        const secretariosData = (data.secretarios) ? data.secretarios : [];

        // Llenar tipos únicos
        const tiposUnicos = Array.from(new Set(juzgadosData.map(j => j.tipo_juzgado).filter(Boolean)));
        tiposUnicos.forEach(t => {
          const o = document.createElement('option'); o.value = t; o.textContent = t; tiposSel.appendChild(o);
        });

        // Función para filtrar juzgados por tipo
        function llenarJuzgadosPorTipo(tipoVal) {
          juzgadoSel.innerHTML = '<option value="" selected>Seleccione un Juzgado</option>';
          juzgadosData.forEach(j => {
            if (!tipoVal || j.tipo_juzgado === tipoVal) {
              const o = document.createElement('option'); o.value = j.id_juzgado; o.textContent = j.nombre_juzgado; juzgadoSel.appendChild(o);
            }
          });
        }

        // Función para cargar secretarios por id_juzgado
        function cargarSecretariosPorJuzgado(idJ) {
          secretarioSel.innerHTML = '<option value="">Seleccione un secretario</option>';
          let hay = false;
          secretariosData.forEach(s => {
            if (String(s.id_juzgado) === String(idJ)) {
              const o = document.createElement('option'); o.value = s.codigo_usu; o.textContent = s.nombre_completo; secretarioSel.appendChild(o); hay = true;
            }
          });
          secretarioSel.disabled = !hay;
        }

        // Handlers
        tiposSel.addEventListener('change', () => {
          llenarJuzgadosPorTipo(tiposSel.value);
          // limpiar secretario
          secretarioSel.innerHTML = '<option value="">Seleccione un secretario</option>';
          secretarioSel.disabled = true;
        });

        juzgadoSel.addEventListener('change', () => {
          cargarSecretariosPorJuzgado(juzgadoSel.value);
        });

        // Preseleccionar valores actuales del depósito (si vienen)
        try {
          const tipoActual = dep.tipo_juzgado || '';
          const idJActual = dep.id_juzgado || '';
          const docSecActual = dep.documento_secretario || '';

          if (tipoActual) tiposSel.value = tipoActual;
          llenarJuzgadosPorTipo(tiposSel.value || tipoActual);

          if (idJActual) juzgadoSel.value = idJActual;
          cargarSecretariosPorJuzgado(juzgadoSel.value || idJActual);

          if (docSecActual) secretarioSel.value = docSecActual;
        } catch (e) {
          console.warn('Error preseleccionando juzgado/secretario', e);
        }
      },
      preConfirm: () => {
        const benefDocVal = document.getElementById('edit_beneficiario_documento').value.trim();
        const exp1Val = document.getElementById('edit_exp1').value.trim();
        const exp2Val = document.getElementById('edit_exp2').value.trim();
        const exp3Val = document.getElementById('edit_exp3').value.trim();
        const depositoVal = document.getElementById('edit_deposito').value.trim().replace(/\D/g, '');
        const secretarioVal = document.getElementById('edit_secretario').value;
        const juzgadoSelVal = document.getElementById('edit_juzgado') ? document.getElementById('edit_juzgado').value : '';
        const fechaRecojoInput = document.getElementById('edit_fecha_recojo');
        const fechaRecojoVal = fechaRecojoInput ? fechaRecojoInput.value : '';
        // Solo capturar observación si el rol lo permite (MAU rol 2 o Admin rol 1)
        const observacionInput = document.getElementById('edit_observacion');
        const observacionVal = (observacionInput && (rolUsuario === 1 || rolUsuario === 2)) ? observacionInput.value.trim() : undefined;

        // Validaciones
        if (!benefDocVal) {
          Swal.showValidationMessage('Debe seleccionar un beneficiario');
          return false;
        }
        
        if (!exp1Val || !exp2Val || !exp3Val) {
          Swal.showValidationMessage('El número de expediente es obligatorio (3 partes)');
          return false;
               }
        
        if (!juzgadoSelVal) {
          Swal.showValidationMessage('Debe seleccionar un Juzgado');
          return false;
        }

        if (!secretarioVal) {
          Swal.showValidationMessage('Debe seleccionar un secretario');
          return false;
        }
        
        if (depositoVal && depositoVal.length !== 13) {
          Swal.showValidationMessage('Si ingresa número de depósito, debe tener exactamente 13 dígitos');
          return false;
        }

        return {
          beneficiario: benefDocVal,
          expediente: `${exp1Val}-${exp2Val}-${exp3Val}`,
          deposito: depositoVal,
          secretario: secretarioVal,
          id_juzgado: juzgadoSelVal,
          fecha_recojo: fechaRecojoVal,
          observacion: observacionVal
        };
      }
    });

    if (!modalResult.isConfirmed) return;

    const valores = modalResult.value;

    // Mostrar loading mientras se guarda
    Swal.fire({
      title: 'Guardando cambios...',
      allowOutsideClick: false,
      didOpen: () => { Swal.showLoading(); }
    });

    try {
      // Enviar al backend
      const formData = new FormData();
      formData.append('id_deposito', idDep);
      formData.append('beneficiario', valores.beneficiario);
      formData.append('nuevo_expediente', valores.expediente);
      if (valores.deposito) formData.append('nuevo_deposito', valores.deposito);
      formData.append('documento_secretario', valores.secretario);
      if (valores.id_juzgado) formData.append('id_juzgado', valores.id_juzgado);
      if (valores.fecha_recojo && puedeEditarFechaRecojo) formData.append('fecha_recojo', valores.fecha_recojo);
      if (valores.observacion !== undefined) formData.append('observacion', valores.observacion);

      const saveResponse = await fetch('../code_back/back_deposito_editar.php', {
        method: 'POST',
        body: formData
      });

      const saveData = await saveResponse.json();

      if (saveData.success) {
        await Swal.fire({
          icon: 'success',
          title: '✅ Actualizado',
          text: saveData.msg || 'Depósito actualizado correctamente',
          confirmButtonText: 'OK'
        });
        location.reload();
      } else {
        throw new Error(saveData.msg || 'Error al guardar');
      }

    } catch (err) {
      console.error('Error al guardar:', err);
      Swal.fire({
        icon: 'error',
        title: '❌ Error',
        text: err.message || 'Error al guardar los cambios',
        confirmButtonText: 'OK'
      });
    }

  } catch (err) {
    console.error('Error:', err);
    Swal.fire({
      icon: 'error',
      title: '❌ Error',
      text: err.message || 'Error al cargar los datos del depósito',
      confirmButtonText: 'OK'
    });
  }
}

// ----------------- DOMContentLoaded: listeners y polling -----------------
document.addEventListener("DOMContentLoaded", () => {
  document.body.addEventListener("click", e => {
    if (e.target.matches(".notificar-icon")) {
      mostrarModalNotificacion(e.target.dataset.dep);
    }
  });

  document.body.addEventListener("click", e => {
    const ic = e.target.closest(".chat-icon");
    if (!ic) return;

    const idDepAttr = ic.dataset.iddep || ic.dataset.id || ic.dataset.idDep || null;
    const rawDep    = ic.dataset.dep || '';
    const ndepAttr  = ic.dataset.ndep || null;

    const isDep13 = /^\d{13}$/.test(rawDep);
    const isDigitsOnly = /^\d+$/.test(rawDep);

    const idDep = idDepAttr || (isDigitsOnly && !isDep13 ? rawDep : null);
    const nDep  = ndepAttr || (isDep13 ? rawDep : '');

    chatDepositoActual = idDep || rawDep || "";
    estadoActualChat   = parseInt(ic.dataset.state, 10) || 0;
    openDepId = chatDepositoActual && String(chatDepositoActual).trim() !== '' ? parseInt(chatDepositoActual,10) : null;

    // abrir modal chat
    const modalChat = document.getElementById("modal-chat");
    if (modalChat) modalChat.style.display = "flex";
    const tituloEl = document.getElementById("chat-titulo");
    if (tituloEl) tituloEl.innerText = `Chat Depósito ${nDep || ''}`;

    // MARCAR VISTO *ANTES* de cargar historial para evitar que la propia apertura genere noti
    if (openDepId) {
      // marcar localmente inmediatamente (optimista) para que el polling no dispare toasts
      window._lastKnownCount = window._lastKnownCount || {};
      window._lastKnownCount[openDepId] = 0;
      window.lastTimestampByDep[keyFor(openDepId)] = new Date().toISOString();

      // llamar al endpoint mark_seen (intenta con documento si está)
      const urls = tryUrlsFor('mark_seen');
      const bodyParts = [`id_deposito=${encodeURIComponent(openDepId)}`];
      if (typeof usuarioActual !== 'undefined' && usuarioActual) {
        bodyParts.push(`documento_usuario=${encodeURIComponent(usuarioActual)}`);
      }
      const body = bodyParts.join('&');
      fetchWithFallback(urls, { method: 'POST', headers: { 'Content-Type':'application/x-www-form-urlencoded' }, body })
        .catch(()=>{}); // ignoramos error, ya hicimos el cambio local

      // ahora cargamos historial con la seguridad de que no nos va a llegar noti por la apertura
      cargarHistorial(chatDepositoActual).then(() => {
        injectChatActions(chatDepositoActual, estadoActualChat, nDep || '');
      });
    } else {
      // fallback: si no hay openDepId (improbable), igual cargamos historial
      cargarHistorial(chatDepositoActual).then(() => {
        injectChatActions(chatDepositoActual, estadoActualChat, nDep || '');
      });
    }
  });

  // Listener para PDF: rastrear clicks en ambos botones, abrir PDFs y luego el modal
  document.body.addEventListener("click", e => {
    const pdfIcon = e.target.closest(".fa-file-pdf, .resolucion-icon");
    if (!pdfIcon) return;

    // Prevenir el comportamiento predeterminado del navegador
    e.preventDefault();

    // Obtener la URL del href del elemento <a> padre
    const link = pdfIcon.closest("a");
    if (!link) return;

    const pdfUrl = link.getAttribute("href");
    if (!pdfUrl) return;

    // Encontrar el row (tr) para obtener los datos del depósito
    const row = pdfIcon.closest("tr");
    if (!row) return;

    // Determinar qué tipo de PDF se clickeó
    const isOrdenPdf = pdfIcon.classList.contains("fa-file-pdf");
    const isResolucionPdf = pdfIcon.classList.contains("resolucion-icon");

    // Obtener el id del depósito desde los data attributes del row
    const idDeposito = row.querySelector(".chat-icon")?.dataset.iddep || 
                       row.querySelector(".chat-icon")?.dataset.dep;

    if (!idDeposito) return;

    // Inicializar rastreador para este depósito si no existe
    const deposKey = String(idDeposito);
    if (!window._pdfClickedTracker[deposKey]) {
      window._pdfClickedTracker[deposKey] = {
        orden_pdf: false,
        resolucion_pdf: false,
        rowElement: row
      };
    }

    // Marcar cuál PDF se clickeó
    if (isOrdenPdf) {
      window._pdfClickedTracker[deposKey].orden_pdf = true;
    } else if (isResolucionPdf) {
      window._pdfClickedTracker[deposKey].resolucion_pdf = true;
    }

    // Abrir el PDF en una nueva pestaña (mantener funcionalidad principal)
    window.open(pdfUrl, "_blank");

    // Verificar si AMBOS PDFs han sido clickeados
    const tracker = window._pdfClickedTracker[deposKey];
    if (tracker.orden_pdf && tracker.resolucion_pdf) {
      // Ambos PDFs han sido clickeados: abrir el modal de chat
      setTimeout(() => {
        const chatIcon = tracker.rowElement.querySelector(".chat-icon");
        if (chatIcon) {
          // Simular un click en el chat-icon para abrir el modal
          chatIcon.click();
        }
        // Limpiar el rastreador después de abrir el modal
        delete window._pdfClickedTracker[deposKey];
      }, 300);
    }
  });

  document.body.addEventListener("click", e => {
    if (!e.target.matches(".trash-icon")) return;
    const nDep = e.target.dataset.dep;
    Swal.fire({
      title:             '¿Estás seguro?',
      text:              `Se eliminará el depósito ${nDep} y todo su historial.`,
      icon:              'warning',
      showCancelButton:  true,
      confirmButtonText: 'Sí, borrar',
      cancelButtonText:  'Cancelar'
    }).then(result => {
      if (!result.isConfirmed) return;
      fetch('../code_back/back_deposito_eliminar.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ n_deposito: nDep })
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          Swal.fire('Borrado', 'El depósito y su historial fueron eliminados.', 'success')
            .then(() => location.reload());
        } else {
          throw new Error(data.msg || 'Error desconocido');
        }
      })
      .catch(err => {
        console.error(err);
        Swal.fire('Error', err.message, 'error');
      });
    });
  });

  document.body.addEventListener("click", e => {
    if (!e.target.matches(".whatsapp-icon")) return;
    const idDep = e.target.dataset.iddep;
    const row = e.target.closest("tr");
    const estadoActual = parseInt(row?.dataset.estado, 10) || 0;
    
    // Estados que permiten cambio de estado: 5, 7, 8
    const estadosConCambio = [5, 7, 8];
    const permitesCambioEstado = estadosConCambio.includes(estadoActual);
    
    Swal.fire({
      icon:              'question',
      title:             '¿Notificar al beneficiario?',
      text:              `Se enviará un mensaje de WhatsApp al beneficiario del depósito`,
      showCancelButton:  true,
      confirmButtonText: 'Sí, notificar',
      cancelButtonText:  'Cancelar'
    }).then(result => {
      if (!result.isConfirmed) return;
      fetch(`../code_back/get_datos_beneficiario.php?deposito=${encodeURIComponent(idDep)}`)
        .then(r => r.json())
        .then(data => {
          if (data.error || !data.telefono) {
            return Swal.fire('Error', 'No hay teléfono registrado para este beneficiario.', 'warning');
          }
          const { link } = buildWaLinkFromData(data);
          window.open(link, '_blank');
          
          // Solo mostrar segundo modal si el estado permite cambio (5, 7 u 8)
          if (!permitesCambioEstado) {
            return; // Salir sin mostrar el segundo modal
          }
          
          // Mostrar segundo modal preguntando si se envió el mensaje
          setTimeout(() => {
            Swal.fire({
              icon:              'question',
              title:             '¿Mensaje enviado?',
              text:              '¿Confirmás que el mensaje de WhatsApp fue enviado exitosamente?',
              showCancelButton:  true,
              confirmButtonText: 'Sí, fue enviado',
              cancelButtonText:  'Cancelar'
            }).then(confirmResult => {
              if (!confirmResult.isConfirmed) return;
              
              // Mapear estado actual al nuevo estado
              let nuevoEstado = null;
              if (estadoActual === 5) nuevoEstado = 6;
              else if (estadoActual === 7) nuevoEstado = 2;
              else if (estadoActual === 8) nuevoEstado = 9;
              
              // Si no es uno de los estados que deben cambiar, no hacer nada
              if (nuevoEstado === null) {
                Swal.fire('Info', 'Este depósito no cambia de estado al notificar.', 'info');
                return;
              }
              
              // Cambiar estado del depósito
              fetch('../code_back/back_deposito_bulk_change_state.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ 
                  ids: [idDep],
                  estado_nuevo: nuevoEstado
                })
              })
              .then(r => r.json())
              .then(data => {
                if (data.ok) {
                  Swal.fire('Éxito', `Estado del depósito actualizado a ${nuevoEstado}.`, 'success')
                    .then(() => {
                      // Recargar la tabla para ver cambios
                      location.reload();
                    });
                } else {
                  throw new Error(data.msg || 'Error desconocido');
                }
              })
              .catch(err => {
                console.error(err);
                Swal.fire('Error', err.message, 'error');
              });
            });
          }, 500);
        });
    });
  });

  const selectAll = document.getElementById("selectAll");
  const bulkBtn   = document.getElementById("bulkWhatsappBtn");

  if (selectAll && bulkBtn) {
    selectAll.addEventListener("change", () => {
      document.querySelectorAll(".whatsapp-bulk").forEach(cb => {
        cb.checked = selectAll.checked;
      });
    });

    bulkBtn.addEventListener("click", () => {
      const seleccionados = Array.from(
        document.querySelectorAll(".whatsapp-bulk:checked")
      )
      .map(cb => cb.dataset.iddep || cb.dataset.idDep)
      .filter(Boolean);

      if (!seleccionados.length) {
        return Swal.fire("Atención", "No has seleccionado ningún depósito.", "info");
      }

      Swal.fire({
        icon: 'question',
        title: `Enviar WhatsApp a ${seleccionados.length} beneficiario(s)?`,
        showCancelButton: true,
        confirmButtonText: 'Sí, enviar',
        cancelButtonText: 'Cancelar'
      }).then(async res => {
        if (!res.isConfirmed) return;
        try {
          const results = await Promise.all(
            seleccionados.map(idDep =>
              fetch(`../code_back/get_datos_beneficiario.php?deposito=${encodeURIComponent(idDep)}`)
                .then(r => r.json())
            )
          );
          results.forEach(data => {
            if (data.error || !data.telefono) return;
            const { link } = buildWaLinkFromData(data);
            window.open(link, '_blank');
          });
        } catch (err) {
          console.error(err);
          Swal.fire("Error", "No se pudo completar el envío masivo.", "error");
        }
      });
    });

  } else {
    console.error("bulkWhatsappBtn o selectAll no encontrados en el DOM");
  }

  const ta = document.getElementById('chat-comentario');
  if (ta) {
    ta.removeEventListener('keypress', _sendKeyHandler);
    ta.addEventListener('keypress', _sendKeyHandler);
  }

  function _sendKeyHandler(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      enviarMensaje();
    }
  }

  document.addEventListener('depositos:updated', function(){
    const ta2 = document.getElementById('chat-comentario');
    if (ta2) {
      ta2.removeEventListener('keypress', _sendKeyHandler);
      ta2.addEventListener('keypress', _sendKeyHandler);
    }
  });

  // Inicia polling cuando cargue página
  startGlobalPolling();
}); // DOMContentLoaded end

// ------------------ Polling global functions ------------------
// Reemplaza toda la función fetchLastByDepositosAndProcess por esta versión:
async function fetchLastByDepositosAndProcess() {
  try {
    const urls = tryUrlsFor('get_last');
    const { resp } = await fetchWithFallback(urls, { method: 'GET' });
    const txt = await resp.text();
    let j;
    try { j = txt ? JSON.parse(txt) : null; } catch(e) { j = null; }
    if (!j) {
      const arr = (() => { try { return JSON.parse(txt); } catch(e){ return null; } })();
      if (Array.isArray(arr)) j = { ok:true, data:arr };
    }
    if (!j || !j.ok || !Array.isArray(j.data)) return;

    // Inicializaciones de almacenamiento local (persisten en la sesión página)
    window._lastKnownCount = window._lastKnownCount || {};   // id_deposito -> número de no_leidos previo
    window._firstPollDone = window._firstPollDone || false;  // marcar la primera carga completa

    for (const row of j.data) {
      const id = row.id_deposito;
      const fecha = row.fecha_historial_deposito || row.fecha || null;
      const no_leidos = parseInt(row.no_leidos || 0);

      // Tipo de evento (normalizamos)
      const tipo = String(row.tipo_evento || row.tipo || '').toUpperCase();

      // Si el tipo no es accionable -> ignoramos notificaciones y toasts de este registro.
      if (tipo && !ACTIONABLE_TYPES.has(tipo)) {
        // aun así actualizamos badge/contador visual si el backend lo provee
        const badge = document.getElementById(`dep-badge-${id}`);
        if (badge) {
          if (no_leidos > 0) {
            badge.textContent = no_leidos > 99 ? '99+' : String(no_leidos);
            badge.classList.remove('d-none');
          } else {
            badge.textContent = '';
            badge.classList.add('d-none');
          }
        }
        // actualizamos lastTimestamp local y lastKnownCount para no repetir
        if (fecha) window.lastTimestampByDep[keyFor(id)] = fecha;
        window._lastKnownCount = window._lastKnownCount || {};
        window._lastKnownCount[id] = no_leidos;
        continue; // saltamos resto del procesamiento para este row
      }

      // Evitar procesar notificaciones de mensajes enviados por mí mismo
      if (typeof usuarioActual !== 'undefined' && usuarioActual && String(row.documento_usuario || '') === String(usuarioActual)) {
        // Actualizar badge / counters locales y seguir
        const badge = document.getElementById(`dep-badge-${id}`);
        if (badge) {
          if (no_leidos > 0) {
            badge.textContent = no_leidos > 99 ? '99+' : String(no_leidos);
            badge.classList.remove('d-none');
          } else {
            badge.textContent = '';
            badge.classList.add('d-none');
          }
        }
        if (fecha) window.lastTimestampByDep[keyFor(id)] = fecha;
        window._lastKnownCount = window._lastKnownCount || {};
        window._lastKnownCount[id] = no_leidos;
        continue;
      }

      // --- resto del procesamiento original ---
      // obtener conteo previo y timestamp previo
      const prevCount = parseInt(window._lastKnownCount[id] || 0);
      const prevTs = window.lastTimestampByDep[keyFor(id)];
      const prevMs = parseTsToMillis(prevTs);
      const fechaMs = parseTsToMillis(fecha);

      // Actualizamos siempre el timestamp local si el servidor provee uno
      // (esto evita volver a notificar lo mismo en ciclos futuros)
      if (fecha && ( !isFinite(prevMs) || fechaMs > prevMs )) {
        // No lo seteamos aún; lo haremos al final del bloque de procesamiento
      }

      // CASO A - cambio en contador de no leidos: prioridad absoluta
      // Si el número de no-leídos aumentó respecto al último poll, es nuevo
      if (no_leidos > prevCount) {
        // Si modal abierto para este depósito -> traer e insertar nuevos mensajes
        if (openDepId && String(openDepId) === String(id)) {
          try {
            const urlsHi = tryUrlsFor('get_historial');
            const bodyLast = prevTs || ''; // pedir posteriores al prevTs (puede estar vacío)
            const body = `id_deposito=${encodeURIComponent(id)}&last=${encodeURIComponent(bodyLast)}`;
            const { resp: r2 } = await fetchWithFallback(urlsHi, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
            const txt2 = await r2.text();
            let jr; try { jr = txt2 ? JSON.parse(txt2) : null } catch (e) { jr = null; }
            const newMsgs = jr && jr.data ? jr.data : (jr && jr.historial ? jr.historial : (Array.isArray(jr) ? jr : []));
            if (Array.isArray(newMsgs) && newMsgs.length > 0) {
              newMsgs.forEach(m => {
                appendSingleMessageToChat({
                  documento_usuario: m.documento_usuario || m.usuario || '',
                  comentario_deposito: m.comentario_deposito || m.comentario || '',
                  fecha_historial_deposito: m.fecha_historial_deposito || m.fecha || new Date().toISOString()
                });
                const uniq = m.id_historial || m.id || `${id}|${(m.fecha_historial_deposito||m.fecha||'')}|${(m.comentario_deposito||m.comentario||'').slice(0,40)}`;
                markShown(uniq);
              });
              // resaltar y marcar visto
              const modal = document.getElementById('modal-chat');
              if (modal) {
                const header = modal.querySelector('h3') || modal.querySelector('#chat-titulo');
                if (header) {
                  header.classList.add('modal-new');
                  setTimeout(()=> header.classList.remove('modal-new'), 2200);
                }
              }
              await markSeen(id); // marcar vistos en servidor
            } else {
              // si no devolvió mensajes (raro), aun así mostramos toast si no estamos en el modal
              if (!(openDepId && String(openDepId) === String(id))) {
                const uniqToastId = `cnt:${id}:${no_leidos}:${fecha || ''}:${String(row.tipo_evento||row.tipo||'')}`;
                if (!window._shownMessages.has(uniqToastId)) {
                  markShown(uniqToastId);
                }
              }
            }
          } catch (e) {
            console.warn('fetch new msgs error (open modal):', e);
            if (!(openDepId && String(openDepId) === String(id))) {
              const uniqToastId = `cnt:${id}:${no_leidos}:${fecha || ''}:${String(row.tipo_evento||row.tipo||'')}`;
              if (!window._shownMessages.has(uniqToastId)) {
                markShown(uniqToastId);
              }
            }
          }
        } else {
          // modal no abierto -> mostrar toast (si no lo mostramos antes por uniq)
          const uniqToastId = `cnt:${id}:${no_leidos}:${fecha || ''}:${String(row.tipo_evento||row.tipo||'')}`;
          if (!window._shownMessages.has(uniqToastId)) {
            markShown(uniqToastId);
          }
        }
      }
      // CASO B - si el contador no aumentó, pero la fecha es más reciente que la que guardamos y el prevCount > 0,
      // podría ser un cambio en contenido del último mensaje; actuamos solo si prevCount>0 (evitar notis en primer poll)
      else if (no_leidos > 0 && isFinite(prevMs) && fecha && fechaMs > prevMs) {
        // mostrar toast solo si no estamos en el modal; si estamos en el modal pedimos nuevos
        if (openDepId && String(openDepId) === String(id)) {
          // similar a arriba: pedir mensajes posteriores a prevTs
          try {
            const urlsHi = tryUrlsFor('get_historial');
            const bodyLast = prevTs || '';
            const body = `id_deposito=${encodeURIComponent(id)}&last=${encodeURIComponent(bodyLast)}`;
            const { resp: r2 } = await fetchWithFallback(urlsHi, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
            const txt2 = await r2.text();
            let jr; try { jr = txt2 ? JSON.parse(txt2) : null } catch (e) { jr = null; }
            const newMsgs = jr && jr.data ? jr.data : (jr && jr.historial ? jr.historial : (Array.isArray(jr) ? jr : []));
            if (Array.isArray(newMsgs) && newMsgs.length > 0) {
              newMsgs.forEach(m => {
                appendSingleMessageToChat({
                  documento_usuario: m.documento_usuario || m.usuario || '',
                  comentario_deposito: m.comentario_deposito || m.comentario || '',
                  fecha_historial_deposito: m.fecha_historial_deposito || m.fecha || new Date().toISOString()
                });
                const uniq = m.id_historial || m.id || `${id}|${(m.fecha_historial_deposito||m.fecha||'')}|${(m.comentario_deposito||m.comentario||'').slice(0,40)}`;
                markShown(uniq);
              });
              await markSeen(id);
            }
          } catch(e){
            console.warn('error fetching new msgs (case B):', e);
          }
        } else {
          const uniqToastId = `ts:${id}:${fecha}:${String(row.tipo_evento||row.tipo||'')}`;
          if (!window._shownMessages.has(uniqToastId)) {
            markShown(uniqToastId);
          }
        }
      }

      // FIN: actualizar el contador y el timestamp locales para próxima iteración
      window._lastKnownCount[id] = no_leidos;
      if (fecha) window.lastTimestampByDep[keyFor(id)] = fecha;
    }

    // marcar que ya pasamos la primera poll (para futuras lógicas que quieran eso)
    window._firstPollDone = true;

  } catch (err) {
    console.warn('fetchLastByDepositos failed:', err);
  }
}

// markSeen mejorado (acepta opts = { documento_usuario })
async function markSeen(id_deposito, opts = {}) {
  if (!id_deposito) return;
  try {
    const urls = tryUrlsFor('mark_seen');
    const parts = [`id_deposito=${encodeURIComponent(id_deposito)}`];
    if (opts.documento_usuario || (typeof usuarioActual !== 'undefined' && usuarioActual)) {
      const doc = opts.documento_usuario || usuarioActual;
      parts.push(`documento_usuario=${encodeURIComponent(doc)}`);
    }
    const body = parts.join('&');
    await fetchWithFallback(urls, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });

    // actualizar estado local optimista para evitar notificaciones repetidas
    window._lastKnownCount = window._lastKnownCount || {};
    window._lastKnownCount[id_deposito] = 0;

    // actualizar badge UI si existe
    try {
      const badge = document.getElementById(`dep-badge-${id_deposito}`);
      if (badge) {
        badge.textContent = '';
        badge.classList.add('d-none');
      }
    } catch(e){ /* noop */ }

    // actualizar timestamp local para que no vuelvan a tratarse como nuevos
    window.lastTimestampByDep[keyFor(id_deposito)] = new Date().toISOString();
  } catch(e) {
    console.warn('markSeen failed', e);
  }
}


function startGlobalPolling() {
  if (pollingTimer) clearInterval(pollingTimer);
  // fetch immediately
  fetchLastByDepositosAndProcess();
  pollingTimer = setInterval(fetchLastByDepositosAndProcess, POLL_INTERVAL_MS);
}

function stopGlobalPolling() {
  if (pollingTimer) { clearInterval(pollingTimer); pollingTimer = null; }
}

// ----------------- ANULAR DEPÓSITO -----------------
async function anularDeposito(icon) {
  const idDep = icon.dataset.iddep || icon.dataset.dep;
  const nDep = icon.dataset.ndep || 'Sin número';
  const nExp = icon.dataset.exp || '';

  if (!idDep) {
    return Swal.fire('Error', 'No se encontró el ID del depósito.', 'error');
  }

  // Confirmar anulación con input para motivo
  const result = await Swal.fire({
    title: '⚠️ ¿Anular Depósito?',
    html: `
      <div style="text-align:left; padding:10px;">
        <p style="margin-bottom:10px;">
          <strong>Expediente:</strong> ${escapeHtml(nExp)}<br>
          <strong>Depósito:</strong> ${escapeHtml(nDep)}
        </p>
        <p style="color:#d9534f; font-weight:600; margin-bottom:15px;">
          ⚠️ Esta acción marcará el depósito como ANULADO y no podrá procesarse.
        </p>
        <label style="display:block; font-weight:600; margin-bottom:5px;">
          Motivo de anulación (opcional):
        </label>
        <textarea id="motivo-anulacion" rows="3" 
                  style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;"
                  placeholder="Ej: Error en el registro, duplicado, solicitud del usuario, etc."></textarea>
      </div>
    `,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Sí, anular',
    cancelButtonText: 'Cancelar',
    confirmButtonColor: '#d9534f',
    cancelButtonColor: '#6c757d',
    focusConfirm: false,
    preConfirm: () => {
      const motivoInput = document.getElementById('motivo-anulacion');
      return {
        motivo: motivoInput ? motivoInput.value.trim() : ''
      };
    }
  });

  if (!result.isConfirmed) return;

  const motivo = result.value.motivo || 'Sin motivo especificado';

  // Mostrar loading
  Swal.fire({
    title: 'Anulando depósito...',
    allowOutsideClick: false,
    didOpen: () => { Swal.showLoading(); }
  });

  try {
    const formData = new FormData();
    formData.append('id_deposito', idDep);
    formData.append('motivo', motivo);

    const response = await fetch('../code_back/back_deposito_anular.php', {
      method: 'POST',
      body: formData
    });

    const data = await response.json();

    if (data.success) {
      await Swal.fire({
        icon: 'success',
        title: '✅ Depósito Anulado',
        html: `
          <p>El depósito ha sido anulado correctamente.</p>
          ${data.deposito ? `
            <div style="margin-top:10px; padding:10px; background:#f9f9f9; border-radius:6px;">
              <strong>Expediente:</strong> ${escapeHtml(data.deposito.n_expediente)}<br>
              <strong>Depósito:</strong> ${escapeHtml(data.deposito.n_deposito || 'Sin número')}
            </div>
          ` : ''}
        `,
        confirmButtonText: 'OK'
      });
      
      // Recargar la página para actualizar la lista
      location.reload();
    } else {
      throw new Error(data.msg || 'Error al anular el depósito');
    }

  } catch (err) {
    console.error('Error al anular:', err);
    Swal.fire({
      icon: 'error',
      title: '❌ Error',
      text: err.message || 'No se pudo anular el depósito',
      confirmButtonText: 'OK'
    });
  }
}

// Agregar event listener para los iconos de anular
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('anular-icon')) {
    anularDeposito(e.target);
  }
});

// También manejar cuando se actualiza la tabla con AJAX
document.addEventListener('depositos:updated', function() {
  // Los event listeners ya están en el document, no necesitamos re-bindear
  console.log('Tabla de depósitos actualizada');
});

