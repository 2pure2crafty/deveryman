/*
 * D'everyman pipeline builder adapter: the ONLY file that talks to litegraph. It
 * loads a canvas model (window.CANVAS_MODEL, built by deveryman_canvas_model) into an
 * editable graph and serialises back to the pipeline-template JSON the server saves
 * (POST to SAVE_URL, which re-runs deveryman_validate_template). See CANVAS-MODEL.md.
 *
 * Forward flow is wired by dragging between the in/out ports (native litegraph links,
 * with fan-in handled). Kickback, escalation, the tag guard, and the tagger are node
 * properties edited in the panel and painted as overlays. Kept behind this one file so
 * a litegraph update/swap touches nothing else.
 */
(function () {
  "use strict";

  var C = {
    bg: "#111", body: "#1c1c1c", title: "#e8e8e8",
    reads: "#8fb7e8", writes: "#9ad0a0",
    flow: "#7ab8ff", kick: "#f87171", esc: "#f0b429", tagchip: "#2563eb",
    warn: "#f87171", muted: "#8a8a8a"
  };
  var KIND = {
    feeder:     { color: "#2c2a1e", chip: "feeder" },
    stage:      { color: "#20293a", chip: null },
    escalation: { color: "#3a2c12", chip: "escalation" },
    gate:       { color: "#2a2a14", chip: "gate", dashed: true },
    merge:      { color: "#14301f", chip: "merge" }
  };
  var COL_W = 250, ROW_H = 190, X0 = 60, Y0 = 260;

  var state = null;   // { model, graph, canvas, palette, tags, saveUrl, csrf, selected }

  function shortDoc(p) { return String(p).split("/").pop(); }
  function kindOf(n) { return KIND[n.kind] ? n.kind : "stage"; }
  function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; }

  // --- theme ---------------------------------------------------------------
  function theme() {
    LiteGraph.NODE_DEFAULT_BGCOLOR = C.body;
    LiteGraph.NODE_DEFAULT_COLOR = "#20293a";
    LiteGraph.NODE_TITLE_COLOR = C.title;
    LiteGraph.NODE_TEXT_COLOR = "#cfcfcf";
    LiteGraph.NODE_TITLE_HEIGHT = 26;
    LiteGraph.NODE_DEFAULT_BOXCOLOR = C.flow;
    LiteGraph.DEFAULT_SHADOW_COLOR = "rgba(0,0,0,0.35)";
    LGraphCanvas.link_type_colors = LGraphCanvas.link_type_colors || {};
    LGraphCanvas.link_type_colors["flow"] = C.flow;
  }

  // --- layout: x by forward depth, y by chain/branch -----------------------
  function layout(model) {
    var byId = {}, i;
    for (i = 0; i < model.nodes.length; i++) byId[model.nodes[i].id] = model.nodes[i];
    // honour saved positions; only auto-place nodes without one
    var needAuto = false;
    for (i = 0; i < model.nodes.length; i++) if (!model.nodes[i].pos) needAuto = true;
    if (!needAuto) { for (i = 0; i < model.nodes.length; i++) { model.nodes[i]._x = model.nodes[i].pos[0]; model.nodes[i]._y = model.nodes[i].pos[1]; } return byId; }

    var preds = {}, escEntry = {};
    for (i = 0; i < model.flow.length; i++) { var e = model.flow[i]; (preds[e.to] = preds[e.to] || []).push(e.from); }
    for (i = 0; i < model.nodes.length; i++) { var kb = model.nodes[i].kickback; if (kb && kb.escalation_target) escEntry[kb.escalation_target] = model.nodes[i].id; }
    var depth = {};
    function d(id, seen) {
      if (depth[id] != null) return depth[id];
      if (seen[id]) return 0; seen[id] = true;
      var ps = preds[id] || [], best = -1, k;
      for (k = 0; k < ps.length; k++) best = Math.max(best, d(ps[k], seen));
      var v = best < 0 ? (escEntry[id] != null ? d(escEntry[id], seen) + 1 : 0) : best + 1;
      depth[id] = v; return v;
    }
    for (i = 0; i < model.nodes.length; i++) d(model.nodes[i].id, {});

    // rows: main baseline 0; escalation -1 (above); tag branches on rows below
    var row = {};
    for (i = 0; i < model.nodes.length; i++) row[model.nodes[i].id] = model.nodes[i].chain === "escalation" ? -1 : 0;
    var br = 1;
    for (i = 0; i < model.flow.length; i++) {
      var g = model.flow[i];
      if (g.when && g.when.tag) { walkBranch(g.to, br); br++; }
    }
    function walkBranch(start, r) {
      var cur = start, guard = 0;
      while (cur != null && byId[cur] && byId[cur].kind !== "merge" && guard++ < 30) {
        if (row[cur] === 0) row[cur] = r;
        var nx = null;
        for (var j = 0; j < model.flow.length; j++) if (model.flow[j].from === cur) { nx = model.flow[j].to; break; }
        cur = nx;
      }
    }
    var used = {};
    for (i = 0; i < model.nodes.length; i++) {
      var id = model.nodes[i].id, key = depth[id] + ":" + row[id], bump = used[key] || 0; used[key] = bump + 1;
      model.nodes[i]._x = X0 + depth[id] * COL_W;
      model.nodes[i]._y = Y0 + row[id] * ROW_H + bump * 48;
    }
    return byId;
  }

  function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath(); ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }

  // --- node card -----------------------------------------------------------
  var REGISTERED = false;
  function registerType() {
    if (REGISTERED) return; REGISTERED = true;
    function AgentNode() {
      this.addInput("", "flow");
      this.addOutput("", "flow");
      this.props = null;
    }
    AgentNode.prototype.ensureFreeInput = function () {
      var free = false, i;
      for (i = 0; i < this.inputs.length; i++) if (!this.inputs[i].link) { free = true; break; }
      if (!free) this.addInput("", "flow");
    };
    AgentNode.prototype.onConnectionsChange = function (t) {
      if (t === LiteGraph.INPUT) this.ensureFreeInput();
    };
    AgentNode.prototype.onSelected = function () { showProps(this); };
    AgentNode.prototype.onDrawForeground = function (ctx) {
      if (this.flags.collapsed) return;
      var m = this.props, x = 10, y = 20, i;
      var kind = kindOf(m);
      if (KIND[kind].dashed) { ctx.save(); ctx.strokeStyle = "#6b6b3a"; ctx.setLineDash([5, 4]); ctx.lineWidth = 1.5; roundRect(ctx, 1, 1, this.size[0] - 2, this.size[1] - 2, 6); ctx.stroke(); ctx.restore(); }
      if (KIND[kind].chip) {
        ctx.font = "9px system-ui,sans-serif"; var c = KIND[kind].chip, w = ctx.measureText(c).width + 10;
        ctx.fillStyle = "#000"; ctx.globalAlpha = 0.25; roundRect(ctx, this.size[0] - w - 8, 6, w, 14, 4); ctx.fill(); ctx.globalAlpha = 1;
        ctx.fillStyle = C.muted; ctx.fillText(c, this.size[0] - w - 3, 16);
      }
      ctx.font = "10px system-ui,sans-serif";
      for (i = 0; i < m.reads.length; i++) { ctx.fillStyle = C.reads; ctx.fillText("← " + shortDoc(m.reads[i]), x, y += 15); }
      for (i = 0; i < m.writes.length; i++) { ctx.fillStyle = C.writes; ctx.fillText("→ " + shortDoc(m.writes[i]), x, y += 15); }
      if (m.is_tagger) { ctx.fillStyle = C.tagchip; ctx.fillText("★ sets the tag", x, y += 15); }
      if (m.branch_tag) { ctx.fillStyle = C.tagchip; ctx.fillText("tag: " + m.branch_tag, x, y += 15); }
      if (this._warn) { ctx.fillStyle = C.warn; ctx.beginPath(); ctx.arc(9, 13, 7, 0, 7); ctx.fill(); ctx.fillStyle = "#fff"; ctx.font = "bold 11px system-ui"; ctx.fillText("!", 6, 17); }
    };
    LiteGraph.registerNodeType("deveryman/agent", AgentNode);
  }

  function nodeHeight(m) {
    var lines = m.reads.length + m.writes.length + (m.is_tagger ? 1 : 0) + (m.branch_tag ? 1 : 0);
    return 34 + lines * 15 + 12;
  }
  function styleNode(node) {
    var m = node.props, kind = kindOf(m);
    node.title = m.id;
    node.color = KIND[kind].color;
    node.bgcolor = C.body;
    node.size = [210, nodeHeight(m)];
  }
  function makeNode(m) {
    var node = LiteGraph.createNode("deveryman/agent");
    node.props = m; styleNode(node); return node;
  }

  // --- edges overlay (kickback / escalation / tag chips / warnings) ---------
  function anchorB(nd) { return [nd.pos[0] + nd.size[0] * 0.5, nd.pos[1] + nd.size[1]]; }
  function anchorT(nd) { return [nd.pos[0] + nd.size[0] * 0.5, nd.pos[1]]; }
  function bez(ctx, a, b, c1, c2, color, w, dash) { ctx.save(); ctx.strokeStyle = color; ctx.lineWidth = w || 2; if (dash) ctx.setLineDash(dash); ctx.beginPath(); ctx.moveTo(a[0], a[1]); ctx.bezierCurveTo(c1[0], c1[1], c2[0], c2[1], b[0], b[1]); ctx.stroke(); ctx.restore(); }
  function arrow(ctx, at, dir, color) { ctx.save(); ctx.fillStyle = color; ctx.translate(at[0], at[1]); ctx.rotate(Math.atan2(dir[1], dir[0])); ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(-9, -4); ctx.lineTo(-9, 4); ctx.closePath(); ctx.fill(); ctx.restore(); }
  function chip(ctx, at, text, bg) { ctx.save(); ctx.font = "10px system-ui,sans-serif"; var w = ctx.measureText(text).width + 12; ctx.fillStyle = bg; roundRect(ctx, at[0] - w / 2, at[1] - 9, w, 18, 9); ctx.fill(); ctx.fillStyle = "#fff"; ctx.fillText(text, at[0] - w / 2 + 6, at[1] + 4); ctx.restore(); }
  function lbl(ctx, at, text, color) { ctx.save(); ctx.font = "9px system-ui,sans-serif"; ctx.fillStyle = color; ctx.fillText(text, at[0], at[1]); ctx.restore(); }

  function drawOverlay(ctx) {
    var model = state.model, graph = state.graph, i;
    var byId = {};
    for (i = 0; i < model.nodes.length; i++) if (model.nodes[i]._node) byId[model.nodes[i].id] = model.nodes[i]._node;
    // tag chips on the native flow link into a branch node
    for (i = 0; i < model.nodes.length; i++) {
      var m = model.nodes[i]; if (!m.branch_tag || !m._node) continue;
      var nd = m._node, p = [nd.pos[0], nd.pos[1] + nd.size[1] * 0.5];
      chip(ctx, [p[0] - 26, p[1]], m.branch_tag, C.tagchip);
    }
    // kickback (red) + escalation (amber)
    var ki = 0;
    for (i = 0; i < model.nodes.length; i++) {
      var n = model.nodes[i], src = n._node, kb = n.kickback; if (!src || !kb) continue;
      if (kb.target && byId[kb.target]) {
        var T = byId[kb.target], a = anchorB(src), b = anchorB(T), drop = 34 + (ki++) * 20;
        bez(ctx, a, b, [a[0], a[1] + drop], [b[0], b[1] + drop], C.kick, 1.6, [6, 4]);
        arrow(ctx, b, [-1, 0.15], C.kick);
        if (kb.doc) lbl(ctx, [(a[0] + b[0]) / 2 - 20, Math.max(a[1], b[1]) + drop + 10], shortDoc(kb.doc), C.kick);
      }
      if (kb.escalation_target && byId[kb.escalation_target]) {
        var E = byId[kb.escalation_target], a2 = anchorT(src), b2 = anchorB(E), rise = 40;
        bez(ctx, a2, b2, [a2[0], a2[1] - rise], [b2[0], b2[1] + rise], C.esc, 1.8);
        arrow(ctx, b2, [0, 1], C.esc);
        lbl(ctx, [(a2[0] + b2[0]) / 2 - 24, Math.min(a2[1], b2[1]) - rise - 4], "escalate (x" + (kb.fail_threshold || 2) + ")", C.esc);
      }
    }
  }

  // --- warnings (exclamation contract) -------------------------------------
  function recomputeWarnings() {
    var model = state.model, writes = {}, i, j;
    for (i = 0; i < model.nodes.length; i++) for (j = 0; j < model.nodes[i].writes.length; j++) writes[model.nodes[i].writes[j]] = true;
    var pre = { "product-backlog.md": 1, "build-queue.md": 1 }; pre[model.backlog_file] = 1;
    var anyGuard = false;
    for (i = 0; i < model.flow.length; i++) if (model.flow[i].when && model.flow[i].when.tag) anyGuard = true;
    for (i = 0; i < model.nodes.length; i++) {
      var n = model.nodes[i]; if (!n._node) continue; var warn = false;
      for (j = 0; j < n.reads.length; j++) { var r = n.reads[j]; if (!writes[r] && !pre[r] && n.kind !== "gate") { warn = true; break; } }
      if (anyGuard && !model.tag_stage) warn = warn || (n.branch_tag ? true : false);
      n._node._warn = warn;
    }
  }

  // --- panels (DOM, our CSS) ----------------------------------------------
  function buildChrome() {
    var wrap = el("div", "dv-chrome"); document.body.appendChild(wrap);

    // palette (add node)
    var pal = el("div", "dv-panel dv-palette");
    pal.appendChild(el("div", "dv-h", "Add node"));
    var sel = el("select"); sel.id = "dv-pal-sel";
    for (var i = 0; i < state.palette.length; i++) { var o = el("option"); o.value = state.palette[i].id; o.textContent = state.palette[i].label + " [" + state.palette[i].kind + "]"; sel.appendChild(o); }
    pal.appendChild(sel);
    var addBtn = el("button", "dv-btn", "+ Add");
    addBtn.onclick = function () { addNodeFromPalette(sel.value); };
    pal.appendChild(addBtn);
    wrap.appendChild(pal);

    // pipeline settings
    var setP = el("div", "dv-panel dv-settings");
    setP.appendChild(el("div", "dv-h", "Pipeline"));
    setP.appendChild(field("Name", "dv-label", state.model.label));
    setP.appendChild(field("Base branch", "dv-base", state.model.meta.base_branch));
    setP.appendChild(field("Release branch", "dv-release", state.model.meta.release_branch));
    setP.appendChild(field("Backlog file", "dv-backlog", state.model.backlog_file));
    setP.appendChild(field("Deployment note", "dv-depnote", state.model.meta.deployment_note));
    setP.appendChild(field("Autonomy (1-5)", "dv-autonomy", state.model.meta.autonomy_level, "number"));
    var save = el("button", "dv-btn dv-save", "Save pipeline");
    save.onclick = doSave;
    setP.appendChild(save);
    var msg = el("div", "dv-msg"); msg.id = "dv-msg"; setP.appendChild(msg);
    wrap.appendChild(setP);

    // node properties (hidden until a node is selected)
    var pr = el("div", "dv-panel dv-props"); pr.id = "dv-props"; pr.style.display = "none";
    wrap.appendChild(pr);
  }

  function field(label, id, val, type) {
    var l = el("label", "dv-f"); l.appendChild(el("span", null, label));
    var inp = el("input"); inp.id = id; inp.type = type || "text"; inp.value = val == null ? "" : val;
    l.appendChild(inp); return l;
  }
  function nodeOptions(currentId, includeBlank) {
    var opts = [], m = state.model, i;
    if (includeBlank) opts.push({ v: "", t: "(none)" });
    for (i = 0; i < m.nodes.length; i++) if (!m.nodes[i].synthetic) opts.push({ v: m.nodes[i].id, t: m.nodes[i].id });
    return opts;
  }
  function selectField(label, opts, cur) {
    var l = el("label", "dv-f"); l.appendChild(el("span", null, label));
    var s = el("select");
    for (var i = 0; i < opts.length; i++) { var o = el("option"); o.value = opts[i].v; o.textContent = opts[i].t; if (opts[i].v === cur) o.selected = true; s.appendChild(o); }
    l.appendChild(s); l._sel = s; return l;
  }

  function showProps(node) {
    state.selected = node;
    var m = node.props, pr = document.getElementById("dv-props"); pr.innerHTML = ""; pr.style.display = "block";
    pr.appendChild(el("div", "dv-h", "Node: " + m.id));
    pr.appendChild(el("div", "dv-sub", m.label + " [" + m.kind + "]"));

    var idF = field("Node id", "", m.id); var idInp = idF.querySelector("input");
    idInp.oninput = function () { m.id = idInp.value.trim(); node.title = m.id; state.canvas.setDirty(true, true); };
    pr.appendChild(idF);

    // chain
    var chainF = selectField("Chain", [{ v: "main", t: "main" }, { v: "escalation", t: "escalation (only when escalated)" }], m.chain || "main");
    chainF._sel.onchange = function () { m.chain = chainF._sel.value; styleNode(node); state.canvas.setDirty(true, true); };
    pr.appendChild(chainF);

    // tag guard (this node is a branch head reached when tag = ...)
    var tagOpts = [{ v: "", t: "(none, not a branch)" }];
    for (var t = 0; t < state.tags.length; t++) tagOpts.push({ v: state.tags[t], t: state.tags[t] });
    var tagF = selectField("Reached when tag", tagOpts, m.branch_tag || "");
    tagF._sel.onchange = function () { m.branch_tag = tagF._sel.value; styleNode(node); recomputeWarnings(); state.canvas.setDirty(true, true); };
    pr.appendChild(tagF);

    // tagger
    var tg = el("label", "dv-f dv-check");
    var cb = el("input"); cb.type = "checkbox"; cb.checked = !!m.is_tagger;
    cb.onchange = function () {
      for (var i = 0; i < state.model.nodes.length; i++) state.model.nodes[i].is_tagger = false;
      m.is_tagger = cb.checked; state.model.tag_stage = cb.checked ? m.id : "";
      for (i = 0; i < state.model.nodes.length; i++) styleNode(state.model.nodes[i]._node ? state.model.nodes[i]._node : {});
      recomputeWarnings(); state.canvas.setDirty(true, true);
    };
    tg.appendChild(cb); tg.appendChild(el("span", null, "This stage sets the routing tag")); pr.appendChild(tg);

    // kickback
    pr.appendChild(el("div", "dv-h2", "Kickback"));
    var kTarget = selectField("Route back to", nodeOptions(m.id, true), (m.kickback && m.kickback.target) || "");
    kTarget._sel.onchange = function () {
      var v = kTarget._sel.value;
      if (!v) { m.kickback = null; }
      else { m.kickback = m.kickback || {}; m.kickback.target = v; if (!m.kickback.doc) m.kickback.doc = "dev-inbox/" + m.id + "-feedback.md"; }
      state.canvas.setDirty(true, true);
    };
    pr.appendChild(kTarget);
    var kDoc = field("Feedback doc", "", (m.kickback && m.kickback.doc) || ""); var kDocI = kDoc.querySelector("input");
    kDocI.oninput = function () { if (m.kickback) m.kickback.doc = kDocI.value; };
    pr.appendChild(kDoc);
    var eTarget = selectField("Escalate to (after fails)", nodeOptions(m.id, true), (m.kickback && m.kickback.escalation_target) || "");
    eTarget._sel.onchange = function () {
      if (!m.kickback) { eTarget._sel.value = ""; return; }
      var v = eTarget._sel.value;
      if (!v) { delete m.kickback.escalation_target; delete m.kickback.fail_threshold; }
      else { m.kickback.escalation_target = v; m.kickback.fail_threshold = m.kickback.fail_threshold || 2; }
      state.canvas.setDirty(true, true);
    };
    pr.appendChild(eTarget);
    var thr = field("Fail threshold", "", (m.kickback && m.kickback.fail_threshold) || 2, "number"); var thrI = thr.querySelector("input");
    thrI.oninput = function () { if (m.kickback && m.kickback.escalation_target) m.kickback.fail_threshold = Math.max(1, parseInt(thrI.value || "2", 10)); };
    pr.appendChild(thr);

    // extra instructions
    pr.appendChild(el("div", "dv-h2", "Per-pipeline tweak"));
    var exWrap = el("label", "dv-f"); exWrap.appendChild(el("span", null, "Extra instructions"));
    var ex = el("textarea"); ex.rows = 3; ex.value = m.extra_instructions || "";
    ex.oninput = function () { m.extra_instructions = ex.value; };
    exWrap.appendChild(ex); pr.appendChild(exWrap);

    var del = el("button", "dv-btn dv-danger", "Delete node");
    del.onclick = function () { removeNode(node); };
    pr.appendChild(del);
  }

  // --- add / remove --------------------------------------------------------
  function addNodeFromPalette(typeId) {
    var t = null, i;
    for (i = 0; i < state.palette.length; i++) if (state.palette[i].id === typeId) t = state.palette[i];
    if (!t) return;
    // unique node id
    var base = typeId, id = base, k = 2, taken = {};
    for (i = 0; i < state.model.nodes.length; i++) taken[state.model.nodes[i].id] = true;
    while (taken[id]) id = base + "-" + (k++);
    var m = { id: id, label: t.label, agent_type: typeId, kind: t.kind, chain: "main",
      reads: t.reads.slice(), writes: t.writes.slice(), kickback: null,
      extra_instructions: "", branch_tag: "", is_tagger: false, pos: null };
    var center = viewCenter();
    m._x = center[0]; m._y = center[1];
    var node = makeNode(m); node.pos = [m._x, m._y];
    state.graph.add(node); m._node = node; state.model.nodes.push(m);
    recomputeWarnings(); state.canvas.setDirty(true, true);
  }
  function removeNode(node) {
    var m = node.props, i;
    state.graph.remove(node);
    for (i = 0; i < state.model.nodes.length; i++) if (state.model.nodes[i] === m) { state.model.nodes.splice(i, 1); break; }
    // clear references to this node from kickback/escalation targets
    for (i = 0; i < state.model.nodes.length; i++) {
      var kb = state.model.nodes[i].kickback; if (!kb) continue;
      if (kb.target === m.id) state.model.nodes[i].kickback = null;
      else if (kb.escalation_target === m.id) { delete kb.escalation_target; delete kb.fail_threshold; }
    }
    if (state.model.tag_stage === m.id) state.model.tag_stage = "";
    document.getElementById("dv-props").style.display = "none";
    recomputeWarnings(); state.canvas.setDirty(true, true);
  }
  function viewCenter() {
    var c = state.canvas, w = c.canvas.width, h = c.canvas.height, s = c.ds.scale, o = c.ds.offset;
    return [w / (2 * s) - o[0], h / (2 * s) - o[1]];
  }

  // --- serialise + save ----------------------------------------------------
  function serialise() {
    var model = state.model, graph = state.graph, i;
    var idByNodeId = {};
    for (i = 0; i < graph._nodes.length; i++) idByNodeId[graph._nodes[i].id] = graph._nodes[i].props ? graph._nodes[i].props.id : null;
    var nodes = [];
    for (i = 0; i < graph._nodes.length; i++) {
      var nd = graph._nodes[i], m = nd.props; if (!m || m.synthetic) continue;
      nodes.push({ id: m.id, agent_type: m.agent_type, kind: m.kind, chain: m.chain || "main",
        kickback: m.kickback || null, extra_instructions: m.extra_instructions || "",
        branch_tag: m.branch_tag || "", is_tagger: !!m.is_tagger,
        pos: [Math.round(nd.pos[0]), Math.round(nd.pos[1])] });
    }
    var flow = [];
    for (var lid in graph.links) {
      var lk = graph.links[lid]; if (!lk) continue;
      var from = idByNodeId[lk.origin_id], to = idByNodeId[lk.target_id];
      if (from && to) flow.push({ from: from, to: to });
    }
    return {
      label: val("dv-label", model.label), description: "",
      tag_stage: model.tag_stage || "", backlog_file: val("dv-backlog", model.backlog_file),
      meta: {
        base_branch: val("dv-base", model.meta.base_branch),
        release_branch: val("dv-release", model.meta.release_branch),
        feature_branch_prefix: model.meta.feature_branch_prefix,
        deployment_note: val("dv-depnote", model.meta.deployment_note),
        autonomy_level: parseInt(val("dv-autonomy", model.meta.autonomy_level), 10) || 3,
        poll_interval: model.meta.poll_interval,
        conductor: model.meta.conductor, conductor_agents: model.meta.conductor_agents
      },
      nodes: nodes, flow: flow
    };
  }
  function val(id, dflt) { var e = document.getElementById(id); return e ? e.value : dflt; }
  function demoModal() {
    var ov = el("div", "dv-modal"); var box = el("div", "box");
    box.appendChild(el("h3", null, "Demo mode"));
    box.appendChild(el("p", null, "This is a demo of D'everyman. Building a pipeline works fully here, "
      + "but saving is disabled: the demo is not connected to any agents, repositories, or AI. In the full "
      + "version, Save writes the pipeline and the daemon runs it."));
    var ok = el("button", "dv-btn", "Got it"); ok.onclick = function () { document.body.removeChild(ov); };
    box.appendChild(ok); ov.appendChild(box);
    ov.onclick = function (e) { if (e.target === ov) document.body.removeChild(ov); };
    document.body.appendChild(ov);
  }
  function doSave() {
    if (state.demo) { demoModal(); return; }
    var msg = document.getElementById("dv-msg"); msg.textContent = "Saving..."; msg.className = "dv-msg";
    var body = new URLSearchParams(); body.set("csrf", state.csrf); body.set("payload", JSON.stringify(serialise()));
    fetch(state.saveUrl, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: body.toString() })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) { msg.textContent = "Saved."; msg.className = "dv-msg ok"; if (res.redirect) setTimeout(function () { location.href = res.redirect; }, 700); }
        else { msg.innerHTML = ""; msg.className = "dv-msg err"; msg.appendChild(el("div", null, "Not saved:")); for (var i = 0; i < res.errors.length; i++) msg.appendChild(el("div", null, "• " + res.errors[i])); }
      })
      .catch(function () { msg.textContent = "Save failed (network)."; msg.className = "dv-msg err"; });
  }

  // --- boot ----------------------------------------------------------------
  function connectFlow(fromNode, toNode) {
    if (!fromNode || !toNode) return;
    toNode.ensureFreeInput && toNode.ensureFreeInput();
    var slot = 0; for (var i = 0; i < toNode.inputs.length; i++) if (!toNode.inputs[i].link) { slot = i; break; }
    fromNode.connect(0, toNode, slot);
  }

  function render(opts) {
    var model = opts.model;
    state = { model: model, palette: opts.palette || [], tags: opts.tags || [], saveUrl: opts.saveUrl, csrf: opts.csrf, demo: !!opts.demo, selected: null };
    theme(); registerType();
    layout(model);
    var graph = new LGraph(), canvas = new LGraphCanvas(opts.canvas, graph);
    state.graph = graph; state.canvas = canvas;
    canvas.clear_background_color = C.bg; canvas.background_image = ""; canvas.render_canvas_border = false; canvas.allow_searchbox = false;

    var i, byId = {};
    for (i = 0; i < model.nodes.length; i++) { var m = model.nodes[i], node = makeNode(m); node.pos = [m._x, m._y]; graph.add(node); m._node = node; byId[m.id] = node; }
    for (i = 0; i < model.flow.length; i++) { var e = model.flow[i]; connectFlow(byId[e.from], byId[e.to]); }
    for (i = 0; i < model.nodes.length; i++) if (model.nodes[i]._node) model.nodes[i]._node.ensureFreeInput();

    recomputeWarnings();
    canvas.onDrawForeground = function (ctx) { drawOverlay(ctx); };
    graph.start();
    buildChrome();

    function fit() {
      var minx = 1e9, miny = 1e9, maxx = -1e9, maxy = -1e9, nd;
      for (var j = 0; j < model.nodes.length; j++) { nd = model.nodes[j]._node; if (!nd) continue; minx = Math.min(minx, nd.pos[0]); miny = Math.min(miny, nd.pos[1]); maxx = Math.max(maxx, nd.pos[0] + nd.size[0]); maxy = Math.max(maxy, nd.pos[1] + nd.size[1]); }
      // pad extra on the sides so the palette (left) and panels (right) do not cover
      // the graph, and below for the kickback labels.
      minx -= 250; maxx += 280; miny -= 90; maxy += 150;
      var bw = maxx - minx, bh = maxy - miny, vw = canvas.canvas.width, vh = canvas.canvas.height;
      var s = Math.min(vw / bw, vh / bh); s = Math.min(s, 1.1);
      if (canvas.ds) { canvas.ds.scale = s; canvas.ds.offset = [vw / (2 * s) - (minx + maxx) / 2, vh / (2 * s) - (miny + maxy) / 2]; }
      canvas.setDirty(true, true);
    }
    var didFit = false;
    function resize() { var e = document.querySelector(opts.canvas), top = e.getBoundingClientRect().top; e.width = window.innerWidth; e.height = window.innerHeight - top; canvas.resize(); if (!didFit) { fit(); didFit = true; } else canvas.setDirty(true, true); }
    window.addEventListener("resize", resize); resize();
    return { graph: graph, canvas: canvas, fit: fit, serialise: serialise };
  }

  window.DeverymanCanvas = { render: render };
})();
