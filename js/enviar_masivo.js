// enviar_masivo.js (REEMPLAZAR)
document.addEventListener("DOMContentLoaded", () => {
  const boton = document.getElementById("bulkWhatsappBtn");
  if (!boton) return;

  function extractIdFromRow(tr, input) {
    const tryList = [
      input && (input.dataset.iddep || input.getAttribute && input.getAttribute('data-iddep')),
      input && (input.dataset.dep || input.getAttribute && input.getAttribute('data-dep')),
      tr && (tr.dataset.iddep || tr.dataset.id_deposito || tr.dataset.id || tr.getAttribute && tr.getAttribute('data-iddep')),
      tr && (tr.dataset.deposito || tr.getAttribute && tr.getAttribute('data-deposito')),
      tr && (tr.dataset['n_deposito'] || tr.getAttribute && tr.getAttribute('data-ndep'))
    ];
    for (const v of tryList) {
      if (v !== undefined && v !== null && String(v).trim() !== '') return String(v).trim();
    }
    return null;
  }

  function normalizePhone(raw) {
    if (!raw) return null;
    let d = String(raw).replace(/\D/g, "");
    if (!d) return null;
    if (d.startsWith("0")) d = d.slice(1);
    if (!d.startsWith("51")) d = "51" + d;
    return d;
  }

  function resolveToId(candidate) {
    if (!candidate) return null;
    candidate = String(candidate).trim();
    if (/^\d+$/.test(candidate) && candidate.length < 13) {
      return parseInt(candidate, 10);
    }
    if (/^\d{13}$/.test(candidate)) {
      const other = document.querySelector(`#tabla-depositos tbody tr[data-deposito="${candidate}"]`);
      if (other) {
        const cand2 = other.dataset.iddep || other.dataset.id || other.dataset.id_deposito ||
                      (other.querySelector && (other.querySelector('.chat-icon') && other.querySelector('.chat-icon').dataset.iddep)) ||
                      (other.querySelector && (other.querySelector('.whatsapp-icon') && other.querySelector('.whatsapp-icon').dataset.iddep));
        if (cand2 && /^\d+$/.test(cand2)) return parseInt(cand2, 10);
      }
      const rows = Array.from(document.querySelectorAll('#tabla-depositos tbody tr'));
      for (const r of rows) {
        if (String(r.dataset.deposito || r.dataset['n_deposito'] || "").trim() === candidate) {
          const cand2 = r.dataset.iddep || r.dataset.id || r.dataset.id_deposito ||
                        (r.querySelector && (r.querySelector('.chat-icon') && r.querySelector('.chat-icon').dataset.iddep));
          if (cand2 && /^\d+$/.test(cand2)) return parseInt(cand2, 10);
        }
      }
      return null;
    }
    return null;
  }

  // ---> Nueva función para formatear fecha de recojo
  function pad2(v) {
    return String(v).padStart(2, '0');
  }

  function formatFechaRecojo(raw) {
    if (!raw) return "";
    raw = String(raw).trim();

    // 1) ISO-like: YYYY-MM-DD HH:MM[:SS] or YYYY/MM/DD HH:MM[:SS] or with 'T'
    let m = raw.match(/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})[ T](\d{1,2}):(\d{2})(?::(\d{2}))?/);
    if (m) {
      const [, y, mo, d, H, Mi /*, S*/] = m;
      return `${pad2(H)}:${pad2(Mi)} ${pad2(d)}/${pad2(mo)}/${y}`;
    }

    // 2) Compact: YYYYMMDDHHMMSS
    m = raw.match(/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/);
    if (m) {
      const [, y, mo, d, H, Mi /*, S*/] = m;
      return `${H}:${Mi} ${d}/${mo}/${y}`;
    }

    // 3) Compact without seconds: YYYYMMDDHHMM
    m = raw.match(/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})$/);
    if (m) {
      const [, y, mo, d, H, Mi] = m;
      return `${H}:${Mi} ${d}/${mo}/${y}`;
    }

    // 4) Date only: YYYY-MM-DD or YYYY/MM/DD
    m = raw.match(/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/);
    if (m) {
      const [, y, mo, d] = m;
      return `${pad2(d)}/${pad2(mo)}/${y}`;
    }

    // 5) If string already contains time first like "08:30 2025-09-15", try to re-order
    m = raw.match(/^(\d{1,2}:\d{2})(?:[:\d\s]*)\s+(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/);
    if (m) {
      const [, time, y, mo, d] = m;
      return `${time} ${pad2(d)}/${pad2(mo)}/${y}`;
    }

    // fallback: devuelve original (por si viene en un formato raro)
    return raw;
  }
  // <--- fin formateador

  async function applyBulkChange(groupEntries) {
    // groupEntries: [{state: 6, ids: [1,2,3]}, ...]
    let totalProcessed = 0;
    for (const ge of groupEntries) {
      const params = new URLSearchParams();
      for (const id of ge.ids) params.append('ids[]', String(id));
      params.append('estado_nuevo', String(ge.state));
      params.append('note', 'Cambio masivo por envío WA');

      console.info("Enviando bulk-change (form):", params.toString());

      try {
        const resp = await fetch("../code_back/back_deposito_bulk_change_state.php", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
          credentials: "same-origin",
          body: params.toString()
        });

        // siempre leer el texto primero para poder debuggear respuestas no-JSON
        const txt = await resp.text();
        let jr = null;
        try { jr = txt ? JSON.parse(txt) : null; } catch(e) {
          console.error("Respuesta no-JSON del servidor:", txt);
          Swal.fire("❌", "Respuesta del servidor no es JSON al intentar cambiar estados. Mira consola (Network / respuesta).", "error");
          return { ok: false, detail: "no-json", raw: txt };
        }

        if (!jr || !jr.ok) {
          console.error("bulk change error response:", jr, "payload:", { ids: ge.ids, state: ge.state });
          Swal.fire("❌", (jr && jr.msg) ? jr.msg : `Error al aplicar cambios para estado ${ge.state}`, "error");
          return { ok: false, detail: "server-error", response: jr };
        }

        const processed = Array.isArray(jr.processed) ? jr.processed.length : (jr.count || jr.processed_count || 0);
        totalProcessed += processed;
      } catch (err) {
        console.error("Error de conexión al endpoint bulk-change:", err);
        Swal.fire("❌", "Error de conexión al aplicar cambios de estado. Revisa Network y logs en el servidor.", "error");
        return { ok: false, detail: "network-error", error: String(err) };
      }
    } // end for

    return { ok: true, processed: totalProcessed };
  }

  boton.addEventListener("click", async () => {
    const filas = Array.from(document.querySelectorAll("input.whatsapp-bulk:checked"));
    if (!filas.length) {
      Swal.fire("Selecciona al menos un depósito para enviar.");
      return;
    }

    const datos   = [];
    const resumen = [];
    const changes = [];
    const debugRows = [];

    filas.forEach(input => {
      const tr = input.closest("tr");
      if (!tr) return;

      const telefonoRaw = (tr.dataset.telefono || "").trim();
      const nombre      = ((tr.dataset.nombre || "Beneficiario") || "").trim();
      const exp         = (tr.dataset.expediente || "").trim();
      const estado      = parseInt(tr.dataset.estado || tr.dataset.id_estado || tr.getAttribute && tr.getAttribute('data-estado') || "0", 10) || 0;
      // tomamos raw y formateamos
      const fechaRecojoRaw = tr.dataset.fechaRecojo || tr.dataset.fecha_recojo || tr.getAttribute && tr.getAttribute('data-fecha-recojo') || "";
      const fechaRecojo = formatFechaRecojo(fechaRecojoRaw);

      const telNorm = normalizePhone(telefonoRaw);
      if (!telNorm) {
        debugRows.push({ reason: 'sin teléfono válido', telefonoRaw, row: tr });
        return;
      }

      let mensaje;
      if (estado === 7) {
        mensaje = `Hola ${nombre}, su expediente ${exp} ya tiene orden de pago lista. Puede acercarse a recogerla en el horario de 8:30am - 1:15pm y 2:45pm - 4:45pm.`;
      } else if (estado === 5) {
        mensaje = fechaRecojo
          ? `Hola ${nombre}, en su expediente ${exp} tiene una orden de pago con fecha de recojo el ${fechaRecojo}. Puede acercarse otro día posterior a este en el horario de 8:30am - 1:15pm y 2:45pm - 4:45pm.`
          : `Hola ${nombre}, en su expediente ${exp} tiene una orden de pago, acerquese en la fecha indicada. Puede recogerla en el horario de 8:30am - 1:15pm y 2:45pm - 4:45pm.`;
      } else if (estado === 8) {
        // Mensaje para reprogramación de fecha de recojo (similar al estado 5 pero específico)
        mensaje = fechaRecojo
          ? `Hola ${nombre}, su expediente ${exp} fue reprogramado y la nueva fecha de recojo es ${fechaRecojo}. Puede acercarse otro día posterior a este en el horario de 8:30am - 1:15pm y 2:45pm - 4:45pm.`
          : `Hola ${nombre}, su expediente ${exp} fue reprogramado para recojo. Acérquese en la fecha indicada. Puede recogerla en el horario de 8:30am - 1:15pm y 2:45pm - 4:45pm.`;
      } else {
        mensaje = `Hola ${nombre}, su expediente ${exp} ya tiene orden de pago lista. Puede acercarse a recogerla en el horario de 8:30am - 1:15pm y 2:45pm - 4:45pm.`;
      }


      const rawId = extractIdFromRow(tr, input);
      const resolvedId = resolveToId(rawId);

      const payloadItem = { telefono: telNorm, mensaje: mensaje, enviado: false };
      if (resolvedId) payloadItem.id_deposito = resolvedId;
      else if (rawId && /^\d{13}$/.test(rawId)) payloadItem.n_deposito = rawId;

      datos.push(payloadItem);
      resumen.push(`📲 ${nombre} (${telefonoRaw})`);

      let target = null;
      if (estado === 5) target = 6;
      else if (estado === 8) target = 9;
      else if (estado === 7) target = 2;

      const idParaCambio = resolvedId || (rawId && /^\d+$/.test(rawId) ? parseInt(rawId,10) : null);
      if (target !== null && idParaCambio) {
        changes.push({ id_deposito: idParaCambio, new_state: target });
      }

      debugRows.push({ rawId, resolvedId, estado, target, telefonoRaw, telNorm, nombre, exp, fechaRecojoRaw, fechaRecojo });
    });

    if (!datos.length) {
      Swal.fire("No se encontraron números válidos para enviar.");
      console.warn("enviar_masivo: no hay datos para enviar. debugRows:", debugRows);
      return;
    }

    const confirmHtml = `<div style="text-align:left;max-height:260px;overflow:auto">${resumen.join("<br>")}</div>`;
    const confirm = await Swal.fire({
      title: `Enviar mensajes a ${datos.length} usuario(s)?`,
      html: confirmHtml,
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Sí, enviar",
      cancelButtonText: "Cancelar",
      width: 650
    });
    if (!confirm.isConfirmed) {
      console.log("enviar_masivo: usuario canceló. debugRows:", debugRows);
      return;
    }

    // 1) Guardar mensajes
    try {
      const r = await fetch("../code_back/guardar_mensajes_ws.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify(datos)
      });
      const txt = await r.text();
      let j;
      try { j = txt ? JSON.parse(txt) : null; } catch(e){ j = null; }
      if (!j || !j.ok) {
        console.error("guardar_mensajes_ws.php error:", txt, j);
        Swal.fire("❌", (j && j.error) || "Error al guardar mensajes en el servidor.", "error");
        return;
      }
      Swal.fire("✅", j.msg || "Mensajes guardados en cola para envío.", "success");
      console.info("guardar_mensajes_ws.php OK:", j);
    } catch (err) {
      console.error("Error al guardar mensajes:", err);
      Swal.fire("❌", "Error de conexión al guardar mensajes.", "error");
      return;
    }

    // 2) Aplicar cambios agrupados por estado
    if (changes.length) {
      const groups = {};
      for (const c of changes) {
        if (!c || !c.id_deposito || !c.new_state) continue;
        if (!groups[c.new_state]) groups[c.new_state] = new Set();
        groups[c.new_state].add(c.id_deposito);
      }
      const groupEntries = Object.entries(groups).map(([state, set]) => ({ state: parseInt(state,10), ids: Array.from(set) }));

      const resChange = await applyBulkChange(groupEntries);
      if (resChange.ok) {
        Swal.fire("✅", `Estados actualizados y enviados: ${resChange.processed}`, "success")
          .then(() => { if (typeof aplicarFiltros === 'function') aplicarFiltros(); else location.reload(); });
      } else {
        console.error("applyBulkChange fallo:", resChange);
        // el applyBulkChange ya mostró alert con detalle
      }
    } else {
      Swal.fire("Info", "Se enviaron los WhatsApp pero no había filas con estado 5/7/8 para cambiar.", "info");
      console.info("enviar_masivo: no hay cambios a aplicar. debug:", debugRows);
    }

  });
});