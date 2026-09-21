/*
 * D'everyman pipeline builder adapter: the ONLY file that talks to litegraph. It
 * takes a canvas model (window.CANVAS_MODEL, built server-side by deveryman_canvas_
 * model) and draws it: agent cards styled by kind, and flow / kickback / escalation /
 * tag-fork edges painted by us so we control their meaning. See docs/CANVAS-MODEL.md.
 *
 * Render-only for now; interactive editing + save is the next milestone. Kept behind
 * this adapter so a litegraph update/swap touches only this file.
 */
(function () {
  "use strict";

  var C = {
    bg: "#111",
    body: "#1c1c1c",
    title: "#e8e8e8",
    reads: "#8fb7e8",
    writes: "#9ad0a0",
    flow: "#7ab8ff",
    kick: "#f87171",
    esc: "#f0b429",
    tagchip: "#2563eb",
    warn: "#f87171",
    muted: "#8a8a8a"
  };
  // per-kind title colour + chip label
  var KIND = {
    feeder:     { color: "#2c2a1e", chip: "feeder" },
    stage:      { color: "#20293a", chip: null },
    escalation: { color: "#3a2c12", chip: "escalation" },
    gate:       { color: "#2a2a14", chip: "gate", dashed: true },
    merge:      { color: "#14301f", chip: "merge" }
  };
  var COL_W = 250, ROW_H = 170, X0 = 60, Y0 = 220;

  function shortDoc(p) { return String(p).split("/").pop(); }
  function nodeKindOf(n) { return KIND[n.kind] ? n.kind : "stage"; }

  // --- theme litegraph -----------------------------------------------------
  function theme() {
    LiteGraph.NODE_DEFAULT_BGCOLOR = C.body;
    LiteGraph.NODE_DEFAULT_COLOR = "#20293a";
    LiteGraph.NODE_TITLE_COLOR = C.title;
    LiteGraph.NODE_TEXT_COLOR = "#cfcfcf";
    LiteGraph.NODE_TITLE_HEIGHT = 26;
    LiteGraph.NODE_DEFAULT_BOXCOLOR = C.flow;
    LiteGraph.DEFAULT_SHADOW_COLOR = "rgba(0,0,0,0.35)";
  }

  // --- layout: x by forward depth, y by chain ------------------------------
  function layout(model) {
    var byId = {}, i;
    for (i = 0; i < model.nodes.length; i++) byId[model.nodes[i].id] = model.nodes[i];
    var preds = {}, incoming = {};
    for (i = 0; i < model.flow.length; i++) {
      var e = model.flow[i];
      (preds[e.to] = preds[e.to] || []).push(e.from);
      incoming[e.to] = true;
    }
    // escalation entry: node.kickback.escalation_target -> from that node
    var escEntry = {};
    for (i = 0; i < model.nodes.length; i++) {
      var kb = model.nodes[i].kickback;
      if (kb && kb.escalation_target) escEntry[kb.escalation_target] = model.nodes[i].id;
    }
    var depth = {};
    function d(id, seen) {
      if (depth[id] != null) return depth[id];
      if (seen[id]) return 0;
      seen[id] = true;
      var ps = preds[id] || [], best = -1, k;
      for (k = 0; k < ps.length; k++) best = Math.max(best, d(ps[k], seen));
      var val;
      if (best < 0) val = escEntry[id] != null ? d(escEntry[id], seen) + 1 : 0;
      else val = best + 1;
      depth[id] = val;
      return val;
    }
    for (i = 0; i < model.nodes.length; i++) d(model.nodes[i].id, {});

    // y row: main = 0; escalation = -1 (above); branches get rows below, keyed by the
    // guarded edge that starts them.
    var row = {};
    for (i = 0; i < model.nodes.length; i++) {
      var n = model.nodes[i];
      row[n.id] = n.chain === "escalation" ? -1 : 0;
    }
    var branchRow = 1;
    for (i = 0; i < model.flow.length; i++) {
      var g = model.flow[i];
      if (g.when && g.when.tag) { assignBranch(g.to, branchRow); branchRow++; }
    }
    function assignBranch(start, r) {
      var cur = start, guard = 0;
      while (cur != null && byId[cur] && byId[cur].kind !== "merge" && guard++ < 30) {
        if (row[cur] === 0) row[cur] = r;
        var nx = null;
        for (var j = 0; j < model.flow.length; j++) if (model.flow[j].from === cur) { nx = model.flow[j].to; break; }
        cur = nx;
      }
    }
    // stack nodes that share (depth,row) so they never overlap
    var used = {};
    for (i = 0; i < model.nodes.length; i++) {
      var id = model.nodes[i].id, key = depth[id] + ":" + row[id], bump = used[key] || 0;
      used[key] = bump + 1;
      model.nodes[i]._x = X0 + depth[id] * COL_W;
      model.nodes[i]._y = Y0 + row[id] * ROW_H + bump * 40;
    }
    return byId;
  }

  // --- build one card ------------------------------------------------------
  var TYPE_REGISTERED = false;
  function registerType() {
    if (TYPE_REGISTERED) return;
    function AgentNode() { this.model = null; }   // a card with no slots; we draw edges
    LiteGraph.registerNodeType("deveryman/agent", AgentNode);
    TYPE_REGISTERED = true;
  }
  function makeNode(n) {
    var node = LiteGraph.createNode("deveryman/agent");
    node.title = n.label || n.id;
    node.model = n;
    var kind = nodeKindOf(n);
    var lines = Math.max(n.reads.length, 0) + Math.max(n.writes.length, 0);
    node.size = [210, 40 + lines * 15 + (n.is_tagger ? 14 : 0) + 8];
    node.color = KIND[kind].color;
    node.bgcolor = C.body;
    node.removable = false;
    node.onDrawForeground = function (ctx) {
      if (this.flags.collapsed) return;
      var y = 20, x = 10, m = this.model;
      ctx.font = "10px system-ui, sans-serif";
      // kind chip (top-right of body)
      if (KIND[nodeKindOf(m)].chip) {
        var chip = KIND[nodeKindOf(m)].chip;
        ctx.font = "9px system-ui, sans-serif";
        var w = ctx.measureText(chip).width + 10;
        ctx.fillStyle = "#000"; ctx.globalAlpha = 0.25;
        roundRect(ctx, this.size[0] - w - 8, 6, w, 14, 4); ctx.fill(); ctx.globalAlpha = 1;
        ctx.fillStyle = C.muted; ctx.fillText(chip, this.size[0] - w - 3, 16);
        ctx.font = "10px system-ui, sans-serif";
      }
      var i;
      for (i = 0; i < m.reads.length; i++) {
        ctx.fillStyle = C.reads; ctx.fillText("← " + shortDoc(m.reads[i]), x, y += 15);
      }
      for (i = 0; i < m.writes.length; i++) {
        ctx.fillStyle = C.writes; ctx.fillText("→ " + shortDoc(m.writes[i]), x, y += 15);
      }
      if (m.is_tagger) { ctx.fillStyle = C.tagchip; ctx.fillText("★ sets the tag", x, y += 15); }
      // exclamation badge (top-left) if this node has a warning
      if (this._warn) {
        ctx.fillStyle = C.warn;
        ctx.beginPath(); ctx.arc(8, 13, 7, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = "#fff"; ctx.font = "bold 11px system-ui"; ctx.fillText("!", 5, 17);
      }
    };
    // dashed border for gates
    if (KIND[kind].dashed) {
      var baseDraw = node.onDrawForeground;
      node.onDrawForeground = function (ctx) {
        ctx.save(); ctx.strokeStyle = "#6b6b3a"; ctx.setLineDash([5, 4]); ctx.lineWidth = 1.5;
        roundRect(ctx, 1, 1, this.size[0] - 2, this.size[1] - 2, 6); ctx.stroke(); ctx.restore();
        baseDraw.call(this, ctx);
      };
    }
    return node;
  }

  function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  // --- edges ---------------------------------------------------------------
  function anchorR(nd) { return [nd.pos[0] + nd.size[0], nd.pos[1] + nd.size[1] * 0.5]; }
  function anchorL(nd) { return [nd.pos[0], nd.pos[1] + nd.size[1] * 0.5]; }
  function anchorB(nd) { return [nd.pos[0] + nd.size[0] * 0.5, nd.pos[1] + nd.size[1]]; }
  function anchorT(nd) { return [nd.pos[0] + nd.size[0] * 0.5, nd.pos[1]]; }

  function bezier(ctx, a, b, c1, c2, color, width, dash) {
    ctx.save();
    ctx.strokeStyle = color; ctx.lineWidth = width || 2;
    if (dash) ctx.setLineDash(dash);
    ctx.beginPath(); ctx.moveTo(a[0], a[1]);
    ctx.bezierCurveTo(c1[0], c1[1], c2[0], c2[1], b[0], b[1]);
    ctx.stroke();
    ctx.restore();
  }
  function arrow(ctx, at, dir, color) {
    ctx.save(); ctx.fillStyle = color;
    ctx.translate(at[0], at[1]); ctx.rotate(Math.atan2(dir[1], dir[0]));
    ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(-9, -4); ctx.lineTo(-9, 4); ctx.closePath(); ctx.fill();
    ctx.restore();
  }
  function chip(ctx, at, text, bg) {
    ctx.save(); ctx.font = "10px system-ui, sans-serif";
    var w = ctx.measureText(text).width + 12;
    ctx.fillStyle = bg; roundRect(ctx, at[0] - w / 2, at[1] - 9, w, 18, 9); ctx.fill();
    ctx.fillStyle = "#fff"; ctx.fillText(text, at[0] - w / 2 + 6, at[1] + 4);
    ctx.restore();
  }
  function label(ctx, at, text, color) {
    ctx.save(); ctx.font = "9px system-ui, sans-serif"; ctx.fillStyle = color;
    ctx.fillText(text, at[0], at[1]); ctx.restore();
  }

  function drawEdges(ctx, model, nodeById) {
    var i, n;
    // forward flow (blue), with a tag chip on guarded edges
    for (i = 0; i < model.flow.length; i++) {
      var e = model.flow[i], A = nodeById[e.from], B = nodeById[e.to];
      if (!A || !B) continue;
      var a = anchorR(A), b = anchorL(B);
      var dx = Math.max(40, (b[0] - a[0]) * 0.5);
      bezier(ctx, a, b, [a[0] + dx, a[1]], [b[0] - dx, b[1]], C.flow, 2);
      arrow(ctx, b, [1, 0], C.flow);
      if (e.when && e.when.tag) chip(ctx, [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2], e.when.tag, C.tagchip);
    }
    // kickback (red) + escalation (amber), from node.kickback
    var ki = 0;
    for (i = 0; i < model.nodes.length; i++) {
      n = model.nodes[i]; var nd = n._node, kb = n.kickback;
      if (!nd || !kb) continue;
      if (kb.target && nodeById[kb.target]) {
        var T = nodeById[kb.target], a2 = anchorB(nd), b2 = anchorB(T);
        var drop = 34 + (ki++) * 20;   // stagger so stacked kickbacks fan out
        bezier(ctx, a2, b2, [a2[0], a2[1] + drop], [b2[0], b2[1] + drop], C.kick, 1.6, [6, 4]);
        arrow(ctx, b2, [-1, 0.15], C.kick);
        if (kb.doc) label(ctx, [(a2[0] + b2[0]) / 2 - 20, Math.max(a2[1], b2[1]) + drop + 10], shortDoc(kb.doc), C.kick);
      }
      if (kb.escalation_target && nodeById[kb.escalation_target]) {
        var E = nodeById[kb.escalation_target], a3 = anchorT(nd), b3 = anchorB(E);
        var rise = 40;
        bezier(ctx, a3, b3, [a3[0], a3[1] - rise], [b3[0], b3[1] + rise], C.esc, 1.8);
        arrow(ctx, b3, [0, 1], C.esc);
        label(ctx, [(a3[0] + b3[0]) / 2 - 24, Math.min(a3[1], b3[1]) - rise - 4],
          "escalate (x" + (kb.fail_threshold || 2) + ")", C.esc);
      }
    }
  }

  // --- warnings (the exclamation contract) ---------------------------------
  function computeWarnings(model, nodeById) {
    var writes = {}, i, j;
    for (i = 0; i < model.nodes.length; i++)
      for (j = 0; j < model.nodes[i].writes.length; j++) writes[model.nodes[i].writes[j]] = true;
    var prewired = { "product-backlog.md": 1, "build-queue.md": 1 };
    prewired[model.backlog_file] = 1;
    for (i = 0; i < model.nodes.length; i++) {
      var n = model.nodes[i];
      // an input nothing upstream writes (unconfigured hand-off)
      for (j = 0; j < n.reads.length; j++) {
        var r = n.reads[j];
        if (!writes[r] && !prewired[r] && n.kind !== "gate") { markWarn(n, nodeById); break; }
      }
      // a tag fork with no tagger
      if (n.kind !== "merge" && hasGuardedOut(model, n.id) && !model.tag_stage) markWarn(n, nodeById);
    }
  }
  function hasGuardedOut(model, id) {
    for (var i = 0; i < model.flow.length; i++)
      if (model.flow[i].from === id && model.flow[i].when && model.flow[i].when.tag) return true;
    return false;
  }
  function markWarn(n, nodeById) { if (n._node) n._node._warn = true; }

  // --- boot ----------------------------------------------------------------
  function render(model, canvasSelector) {
    theme();
    registerType();
    var byId = layout(model);
    var graph = new LGraph();
    var canvas = new LGraphCanvas(canvasSelector, graph);
    canvas.clear_background_color = C.bg;
    canvas.background_image = "";
    canvas.render_canvas_border = false;
    canvas.allow_searchbox = false;
    canvas.allow_reconnect_links = false;

    for (var i = 0; i < model.nodes.length; i++) {
      var n = model.nodes[i], node = makeNode(n);
      node.pos = [n._x, n._y];
      graph.add(node);
      n._node = node;
    }
    var nodeById = {};
    for (i = 0; i < model.nodes.length; i++) nodeById[model.nodes[i].id] = model.nodes[i]._node;
    // map id -> node for edge drawing (the model nodes carry _node)
    computeWarnings(model, nodeById);

    var modelNodeById = {};
    for (i = 0; i < model.nodes.length; i++) modelNodeById[model.nodes[i].id] = model.nodes[i]._node;

    canvas.onDrawBackground = function (ctx) {
      drawEdges(ctx, model, modelNodeById);
    };
    graph.start();

    function fit() {
      var minx = 1e9, miny = 1e9, maxx = -1e9, maxy = -1e9, i, nd;
      for (i = 0; i < model.nodes.length; i++) {
        nd = model.nodes[i]._node;
        minx = Math.min(minx, nd.pos[0]); miny = Math.min(miny, nd.pos[1]);
        maxx = Math.max(maxx, nd.pos[0] + nd.size[0]); maxy = Math.max(maxy, nd.pos[1] + nd.size[1]);
      }
      minx -= 50; maxx += 50; miny -= 70; maxy += 120;   // pad for edge labels above/below
      var bw = maxx - minx, bh = maxy - miny;
      var vw = canvas.canvas.width, vh = canvas.canvas.height;
      var s = Math.min(vw / bw, vh / bh); s = Math.min(s, 1.1);
      var cx = (minx + maxx) / 2, cy = (miny + maxy) / 2;
      if (canvas.ds) {
        canvas.ds.scale = s;
        canvas.ds.offset = [vw / (2 * s) - cx, vh / (2 * s) - cy];
      }
      canvas.setDirty(true, true);
    }
    function resize() {
      var el = document.querySelector(canvasSelector);
      var top = el.getBoundingClientRect().top;
      el.width = window.innerWidth;
      el.height = window.innerHeight - top;
      canvas.resize();
      fit();
    }
    window.addEventListener("resize", resize);
    resize();
    return { graph: graph, canvas: canvas, fit: fit };
  }

  window.DeverymanCanvas = { render: render };
})();
