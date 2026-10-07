/*
 * 表情选择器（QQ 风格表情）
 *
 * 前台评论条、用户中心回复框共用同一个组件：
 *   WmEmoji.attach(textarea, triggerBtn)  —— 挂载后自动在按钮旁生成表情面板，
 *   点击表情插入到光标位置（并把光标停在表情之后），面板点击外部自动收起。
 * 「最近使用」按输入框分组存 localStorage，最多保留 24 个。
 * 全部是 Unicode 表情字符，插入的是真字符而非 [微笑] 这类占位符，
 * 因此无需服务端再做替换，评论渲染与字数统计都不会受影响。
 */
(function (win) {
  'use strict';

  var GROUPS = [
    {
      name: '表情',
      list: ['😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '🙃', '😉', '😊',
             '😇', '🥰', '😍', '😘', '😗', '😚', '😋', '😛', '😜', '🤪', '😝', '🤗',
             '🤭', '🤔', '🤐', '😐', '😑', '😶', '😏', '😒', '🙄', '😬', '😌', '😔',
             '😪', '😴', '😷', '🤒', '🥵', '🥶', '😵', '🤯', '😎', '🤓', '🧐', '😕',
             '😟', '🙁', '😮', '😯', '😲', '😳', '🥺', '😦', '😧', '😨', '😰', '😥',
             '😢', '😭', '😱', '😖', '😣', '😞', '😓', '😩', '😫', '🥱', '😤', '😡',
             '😠', '🤬', '😈', '👿', '💀', '💩', '🤡', '👻', '👽', '🤖', '😺', '😸',
             '😹', '😻', '😼', '😽', '🙀', '😿', '😾']
    },
    {
      name: '手势',
      list: ['👋', '🤚', '🖐️', '✋', '🖖', '👌', '🤏', '✌️', '🤞', '🤟', '🤘', '🤙',
             '👈', '👉', '👆', '👇', '☝️', '👍', '👎', '✊', '👊', '🤛', '🤜', '👏',
             '🙌', '👐', '🤲', '🤝', '🙏', '✍️', '💅', '🤳', '💪', '🦾', '🦿', '🦵',
             '🦶', '👂', '👃', '🧠', '👀', '👁️', '👅', '👄', '💋', '❤️', '🧡', '💛',
             '💚', '💙', '💜', '🖤', '🤍', '🤎', '💔', '❣️', '💕', '💞', '💓', '💗',
             '💖', '💘', '💝', '💟', '💯', '💢', '💥', '💫', '💦', '💨', '🕳️', '💬',
             '🗨️', '🗯️', '💭', '💤']
    },
    {
      name: '动物',
      list: ['🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', '🐨', '🐯', '🦁', '🐮',
             '🐷', '🐽', '🐸', '🐵', '🙈', '🙉', '🙊', '🐔', '🐧', '🐦', '🐤', '🐣',
             '🦆', '🦅', '🦉', '🦇', '🐺', '🐗', '🐴', '🦄', '🐝', '🐛', '🦋', '🐌',
             '🐞', '🐜', '🕷️', '🦂', '🐢', '🐍', '🦎', '🐙', '🦑', '🦐', '🦀', '🐡',
             '🐠', '🐟', '🐬', '🐳', '🐋', '🦈', '🐊', '🐅', '🐆', '🦓', '🦍', '🐘',
             '🦛', '🐪', '🦏', '🦒', '🐄', '🐖', '🐑', '🐕', '🐩', '🦮', '🐈', '🐓',
             '🦃', '🦚', '🦜', '🦢', '🕊️', '🐇', '🦝', '🦨', '🦡', '🦦', '🦥', '🐁',
             '🐀', '🐿️', '🦔']
    },
    {
      name: '食物',
      list: ['🍏', '🍎', '🍐', '🍊', '🍋', '🍌', '🍉', '🍇', '🍓', '🍒', '🍑', '🥭',
             '🍍', '🥥', '🥝', '🍅', '🍆', '🥑', '🥦', '🥬', '🥒', '🌶️', '🌽', '🥕',
             '🧄', '🧅', '🥔', '🍠', '🥐', '🥯', '🍞', '🥖', '🥨', '🧀', '🥚', '🍳',
             '🧈', '🥞', '🧇', '🥓', '🥩', '🍗', '🍖', '🌭', '🍔', '🍟', '🍕', '🥪',
             '🥙', '🧆', '🌮', '🌯', '🥗', '🥘', '🍝', '🍜', '🍲', '🍛', '🍣', '🍱',
             '🥟', '🍤', '🍙', '🍚', '🍘', '🍥', '🥠', '🍢', '🍡', '🍧', '🍨', '🍦',
             '🥧', '🧁', '🍰', '🎂', '🍮', '🍭', '🍬', '🍫', '🍿', '🍩', '🍪', '🌰',
             '🥜', '🍯']
    },
    {
      name: '符号',
      list: ['✅', '❌', '❗', '❓', '⭕', '🚫', '⚠️', '♻️', '🔔', '🔕', '🎵', '🎶',
             '💡', '🔍', '🔒', '🔓', '🔑', '💰', '💴', '💎', '🎁', '🎈', '🎉', '🎊',
             '🏆', '🥇', '🎯', '🔥', '⭐', '🌟', '✨', '⚡', '☀️', '🌙', '⛅', '☁️',
             '🌈', '❄️', '☔', '🌊', '🍀', '🌸', '🌹', '🌻', '🌿', '🍂', '☕', '🍺',
             '🎂', '🎉', '🎁', '🕯️', '📌', '📎', '📷', '🎧', '🎬', '🏆', '🚀', '👑']
    }
  ];

  var RECENT_MAX = 24;
  var panel = null;
  var boundTextarea = null;
  var boundTrigger = null;
  var activeGroup = 0;
  var storageKey = 'wm_emoji_recent';

  function readRecent() {
    try {
      var raw = win.localStorage.getItem(storageKey);
      var list = raw ? JSON.parse(raw) : [];
      return Array.isArray(list) ? list : [];
    } catch (e) {
      return [];
    }
  }

  function pushRecent(face) {
    try {
      var list = readRecent().filter(function (c) { return c !== face; });
      list.unshift(face);
      if (list.length > RECENT_MAX) { list = list.slice(0, RECENT_MAX); }
      win.localStorage.setItem(storageKey, JSON.stringify(list));
    } catch (e) {}
  }

  function buildPanel() {
    var el = document.createElement('div');
    el.className = 'emoji-panel';
    el.hidden = true;
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', '表情选择');

    var tabs = document.createElement('div');
    tabs.className = 'emoji-tabs';
    GROUPS.forEach(function (g, i) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'emoji-tab' + (i === 0 ? ' on' : '');
      b.textContent = g.name;
      b.addEventListener('click', function () {
        activeGroup = i;
        Array.prototype.forEach.call(tabs.children, function (c) { c.classList.remove('on'); });
        b.classList.add('on');
        renderGrid();
      });
      tabs.appendChild(b);
    });

    var grid = document.createElement('div');
    grid.className = 'emoji-grid';

    var recent = document.createElement('div');
    recent.className = 'emoji-recent';
    recent.hidden = true;

    var tip = document.createElement('div');
    tip.className = 'emoji-tip';
    tip.textContent = '点击表情插入到光标位置';

    el.appendChild(tabs);
    el.appendChild(recent);
    el.appendChild(grid);
    el.appendChild(tip);
    document.body.appendChild(el);
    return el;
  }

  function renderRecent() {
    var recentBox = panel.querySelector('.emoji-recent');
    if (!recentBox) { return; }
    var list = readRecent();
    recentBox.hidden = list.length === 0;
    recentBox.innerHTML = '';
    if (!list.length) { return; }
    var label = document.createElement('span');
    label.className = 'emoji-sub';
    label.textContent = '最近使用';
    recentBox.appendChild(label);
    list.forEach(function (face) {
      recentBox.appendChild(makeBtn(face));
    });
  }

  function renderGrid() {
    renderRecent();
    var grid = panel.querySelector('.emoji-grid');
    var group = GROUPS[activeGroup] || GROUPS[0];
    grid.innerHTML = '';
    group.list.forEach(function (face) {
      grid.appendChild(makeBtn(face));
    });
  }

  function makeBtn(face) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'emoji-cell';
    b.textContent = face;
    b.title = face;
    b.setAttribute('aria-label', '插入表情 ' + face);
    b.addEventListener('click', function () {
      insert(face);
    });
    return b;
  }

  function insert(face) {
    if (!boundTextarea) { return; }
    var ta = boundTextarea;
    ta.focus();
    var start = typeof ta.selectionStart === 'number' ? ta.selectionStart : ta.value.length;
    var end = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : ta.value.length;
    ta.value = ta.value.slice(0, start) + face + ta.value.slice(end);
    var pos = start + face.length;
    try {
      ta.setSelectionRange(pos, pos);
    } catch (e) {
      // 部分浏览器（如旧版 input）不支持 setSelectionRange，忽略即可
    }
    // 触发 input 事件，保证字数统计、下发前的状态同步等监听器能感知
    try {
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    } catch (e) {}
    pushRecent(face);
    // 「最近使用」要重建，但不能在这里同步重建：
    // innerHTML = '' 会把用户刚点的那个按钮从 DOM 上摘掉，而此刻点击事件
    // 还在冒泡。冒泡到 document 后 ev.target.closest('.emoji-panel') 对已脱离
    // 文档的节点返回 null，就会被外面的「点击空白处关闭」判定为点了面板外，
    // 结果评论框和表情面板一起被收起（点分组/点主网格都正常，只有「最近使用」会）。
    // 推迟到本次事件派发结束后再重绘，事件链上的节点就还挂在面板里。
    setTimeout(renderRecent, 0);
  }

  function place(trigger) {
    var r = trigger.getBoundingClientRect();
    // 贴边保护：靠近视口右/下边缘时改为向上、向左展开
    var h = panel.offsetHeight || 268;
    var w = panel.offsetWidth || 300;
    var top = r.top - h - 8;
    if (top < 6) {
      top = Math.min(r.bottom + 8, win.innerHeight - h - 6);
    }
    var left = r.left;
    if (left + w > win.innerWidth - 6) {
      left = Math.max(6, win.innerWidth - w - 6);
    }
    panel.style.top = Math.max(6, top) + 'px';
    panel.style.left = left + 'px';
  }

  function closePanel() {
    if (panel) { panel.hidden = true; }
  }

  function openPanel() {
    if (!panel) { return; }
    renderGrid();
    panel.hidden = false;
    place(boundTrigger);
  }

  // 判断点击是否落在面板内。
  // 不能只靠 closest()：面板内容被重绘时，被点的节点可能已脱离文档，
  // closest('.emoji-panel') 会返回 null，导致误判成「点了外面」而收起面板。
  function inPanel(t) {
    if (!t) { return false; }
    if (panel && panel.contains(t)) { return true; }
    return !!(t.closest && t.closest('.emoji-panel'));
  }

  document.addEventListener('click', function (ev) {
    if (!panel || panel.hidden) { return; }
    var t = ev.target;
    if (inPanel(t)) { return; }
    if (t && t.closest && t.closest('[data-emoji-trigger]')) { return; }
    closePanel();
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && panel && !panel.hidden) { closePanel(); }
  });

  win.addEventListener('resize', function () {
    if (panel && !panel.hidden && boundTrigger) { place(boundTrigger); }
  });

  function attach(textarea, trigger) {
    if (!textarea || !trigger) { return; }
    if (!panel) { panel = buildPanel(); }

    boundTextarea = textarea;
    boundTrigger = trigger;

    trigger.setAttribute('data-emoji-trigger', '1');
    trigger.setAttribute('type', 'button');
    trigger.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      if (panel && !panel.hidden && boundTrigger === trigger) {
        closePanel();
      } else {
        boundTextarea = textarea;
        boundTrigger = trigger;
        openPanel();
      }
    });
  }

  win.WmEmoji = { attach: attach, close: closePanel, groups: GROUPS };
}(window));
