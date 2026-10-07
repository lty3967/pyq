/* 前台交互：点赞、评论、图片查看、浏览上报 */
(function () {
  'use strict';
  var CFG = window.WM || {};
  var API = 'api.php';

  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }

  var toastEl = $('#toast'), toastTimer = null;
  function toast(msg) {
    if (!toastEl) { return; }
    toastEl.textContent = msg;
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, 2200);
  }

  function post(data) {
    var body = new URLSearchParams();
    body.append('_token', CFG.token || '');
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    return fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'fetch' },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (r) { return r.json().catch(function () { return { ok: false, msg: '服务器响应异常' }; }); });
  }

  /* ---------------- 操作面板 ---------------- */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-act]');

    // 点击空白关闭所有面板
    if (!btn) {
      $$('.panel').forEach(function (p) { p.hidden = true; });
      return;
    }
    var act = btn.getAttribute('data-act');

    if (act === 'panel') {
      var panel = btn.closest('.body').querySelector('.panel');
      if (!panel) { return; }
      var willOpen = panel.hidden;
      $$('.panel').forEach(function (p) { p.hidden = true; });
      panel.hidden = !willOpen;
      ev.stopPropagation();
      return;
    }

    if (act === 'like') {
      ev.stopPropagation();
      if (btn.dataset.busy === '1') { return; }
      btn.dataset.busy = '1';
      var id = btn.getAttribute('data-id');
      post({ act: 'like', post_id: id }).then(function (res) {
        btn.dataset.busy = '';
        if (!res.ok) { toast(res.msg || '操作失败'); return; }
        var liked = !!(res.data && res.data.liked);
        var n = (res.data && res.data.likes) || 0;
        btn.classList.toggle('on', liked);
        var txt = btn.querySelector('.txt');
        if (txt) { txt.textContent = liked ? '取消' : '赞'; }
        var inter = $('[data-inter="' + id + '"]');
        var likeBox = $('[data-likes="' + id + '"]');
        if (likeBox) {
          var numEl = likeBox.querySelector('.n');
          if (numEl) { numEl.textContent = String(n); }
          likeBox.classList.toggle('hide', n <= 0);
        }
        if (inter) {
          var hasCmt = inter.querySelector('.cmt') !== null;
          inter.classList.toggle('hide', n <= 0 && !hasCmt);
        }
        var panelEl = btn.closest('.panel');
        if (panelEl) { panelEl.hidden = true; }
      }).catch(function () { btn.dataset.busy = ''; toast('网络异常'); });
      return;
    }

    if (act === 'comment') {
      ev.stopPropagation();
      openCmt(btn.getAttribute('data-id'), 0, '');
      var p = btn.closest('.panel');
      if (p) { p.hidden = true; }
      return;
    }
  });

  /* ---------------- 回复某条评论 ---------------- */
  document.addEventListener('click', function (ev) {
    var who = ev.target.closest('.cmt .who[data-reply]');
    if (!who) { return; }
    var item = who.closest('.item');
    if (!item) { return; }
    openCmt(item.getAttribute('data-id'), who.getAttribute('data-reply'), who.getAttribute('data-name') || '');
  });

  /* ---------------- 评论框 ---------------- */
  var bar = $('#cmtBar'), form = $('#cmtForm'), tip = $('#cbTip');
  var NICK_KEY = 'wm_nick', MAIL_KEY = 'wm_mail';

  function openCmt(postId, parentId, name) {
    if (!bar || !form) { return; }
    form.post_id.value = postId;
    form.parent_id.value = parentId || 0;
    bar.hidden = false;
    var ta = form.content;
    ta.placeholder = (parentId && name) ? ('回复 ' + name + '：') : '评论…';
    try {
      if (!form.nickname.value) { form.nickname.value = localStorage.getItem(NICK_KEY) || ''; }
      if (!form.email.value) { form.email.value = localStorage.getItem(MAIL_KEY) || ''; }
    } catch (e) {}
    setTimeout(function () { ta.focus(); }, 50);
  }

  function closeCmt() {
    if (bar) { bar.hidden = true; }
    if (form) { form.content.value = ''; form.parent_id.value = 0; }
    if (window.WmEmoji) { window.WmEmoji.close(); }
  }

  /* 表情选择器：把光标停在插入的表情之后，方便连续输入 */
  (function initEmoji() {
    var btn = $('#cmtEmoji');
    if (!btn || !form || !window.WmEmoji) { return; }
    window.WmEmoji.attach(form.content, btn);
  }());

  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = form.querySelector('.cb-send');
      var nickname = form.nickname.value.trim();
      var content = form.content.value.trim();
      if (nickname.length < 1) { toast('请填写昵称'); return; }
      if (content.length < 1) { toast('请输入评论内容'); return; }
      btn.disabled = true;
      post({
        act: 'comment',
        post_id: form.post_id.value,
        parent_id: form.parent_id.value,
        nickname: nickname,
        email: form.email.value.trim(),
        content: content
      }).then(function (res) {
        btn.disabled = false;
        toast(res.msg || (res.ok ? '成功' : '失败'));
        if (!res.ok) { return; }
        try {
          localStorage.setItem(NICK_KEY, nickname);
          localStorage.setItem(MAIL_KEY, form.email.value.trim());
        } catch (e) {}
        if (res.data && res.data.status === 1 && res.data.html_content) {
          appendComment(form.post_id.value, res.data);
        }
        closeCmt();
      }).catch(function () { btn.disabled = false; toast('网络异常'); });
    });
  }

  function appendComment(postId, d) {
    var box = $('[data-cmts="' + postId + '"]');
    var inter = $('[data-inter="' + postId + '"]');
    if (!box) { return; }
    var div = document.createElement('div');
    div.className = 'cmt';
    var b = document.createElement('b');
    b.className = 'who';
    b.setAttribute('data-reply', String(d.id));
    b.setAttribute('data-name', decodeEntities(d.html_nickname));
    b.textContent = decodeEntities(d.html_nickname);
    div.appendChild(b);
    div.appendChild(document.createTextNode('：'));
    var span = document.createElement('span');
    span.className = 'c-text';
    span.innerHTML = d.html_content; // 服务端已转义
    div.appendChild(span);
    box.appendChild(div);
    if (inter) { inter.classList.remove('hide'); }
  }

  function decodeEntities(s) {
    var t = document.createElement('textarea');
    t.innerHTML = String(s || '');
    return t.value;
  }

  document.addEventListener('click', function (ev) {
    if (!bar || bar.hidden) { return; }
    var t = ev.target;
    if (!t || !t.closest) { return; }
    if (t.closest('#cmtBar')) { return; }
    // 表情面板挂在 body 上，不属于评论框内部，点了不能把评论框收起来。
    // 用 contains 而不只是 closest()：面板重绘时节点可能已脱离文档，
    // closest() 会返回 null 而被误判成外部点击。
    var emojiPanel = document.querySelector('.emoji-panel');
    if ((emojiPanel && emojiPanel.contains(t)) || t.closest('.emoji-panel')) { return; }
    if (t.closest('[data-emoji-trigger]')) { return; }
    if (t.closest('[data-act="comment"]')) { return; }
    if (t.closest('.cmt .who[data-reply]')) { return; }
    closeCmt();
  });

  /* ---------------- 图片查看器 ---------------- */
  var viewer = $('#viewer'), vImg = $('#viewerImg'), vCount = $('#viewerCount');
  var group = [], gIdx = 0;

  document.addEventListener('click', function (ev) {
    var img = ev.target.closest('.grid .cell img');
    if (!img || !viewer) { return; }
    var grid = img.closest('.grid');
    group = $$('img', grid).map(function (i) { return i.getAttribute('data-full') || i.src; });
    gIdx = group.indexOf(img.getAttribute('data-full') || img.src);
    if (gIdx < 0) { gIdx = 0; }
    show();
    viewer.hidden = false;
    document.body.style.overflow = 'hidden';
  });

  function show() {
    if (!vImg) { return; }
    vImg.src = group[gIdx] || '';
    if (vCount) { vCount.textContent = group.length > 1 ? (gIdx + 1) + ' / ' + group.length : ''; }
  }

  function closeViewer() {
    if (!viewer) { return; }
    viewer.hidden = true;
    if (vImg) { vImg.src = ''; }
    document.body.style.overflow = '';
  }

  if (viewer) {
    viewer.addEventListener('click', function (ev) {
      if (ev.target.id === 'viewerClose' || ev.target === viewer) { closeViewer(); return; }
      if (ev.target === vImg && group.length > 1) { gIdx = (gIdx + 1) % group.length; show(); }
    });
    var sx = 0;
    viewer.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
    viewer.addEventListener('touchend', function (e) {
      var dx = e.changedTouches[0].clientX - sx;
      if (Math.abs(dx) < 45 || group.length < 2) { return; }
      gIdx = dx < 0 ? (gIdx + 1) % group.length : (gIdx - 1 + group.length) % group.length;
      show();
    });
  }

  document.addEventListener('keydown', function (ev) {
    if (viewer && !viewer.hidden) {
      if (ev.key === 'Escape') { closeViewer(); }
      if (ev.key === 'ArrowRight' && group.length > 1) { gIdx = (gIdx + 1) % group.length; show(); }
      if (ev.key === 'ArrowLeft' && group.length > 1) { gIdx = (gIdx - 1 + group.length) % group.length; show(); }
      return;
    }
    if (ev.key === 'Escape' && bar && !bar.hidden) { closeCmt(); }
  });

  /* ---------------- 浏览上报（进入视口一次） ---------------- */
  if ('IntersectionObserver' in window) {
    var reported = {};
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) { return; }
        var id = en.target.getAttribute('data-id');
        if (!id || reported[id]) { return; }
        reported[id] = 1;
        io.unobserve(en.target);
        post({ act: 'view', post_id: id }).catch(function () {});
      });
    }, { threshold: 0.5 });
    $$('.item').forEach(function (el) { io.observe(el); });
  }

  /* ---------------- 分享音乐卡片 + 底部播放条 ---------------- */
  (function musicPlayer() {
    var player = $('#musicPlayer');
    var audio = $('#mpAudio');
    if (!player || !audio) { return; }

    var mpCover = $('#mpCover'), mpName = $('#mpName'), mpArtist = $('#mpArtist');
    var mpDisc = $('#mpDisc'), mpToggle = $('#mpToggle'), mpIco = $('#mpIco');
    var mpClose = $('#mpClose'), mpBar = $('#mpBar');
    var currentSrc = '';

    // 封面加载失败时降级为音符占位，避免出现破图
    $$('.music-card .mc-cover img').forEach(function (im) {
      im.addEventListener('error', function () { im.style.display = 'none'; });
    });

    function setIco(playing) {
      if (mpIco) { mpIco.textContent = playing ? '❚❚' : '▶'; }
      if (mpDisc) { mpDisc.classList.toggle('spin', playing); }
    }

    function show(data) {
      if (mpName) { mpName.textContent = data.name || ''; }
      if (mpArtist) { mpArtist.textContent = data.artist || ''; }
      if (mpCover) {
        if (data.cover) {
          mpCover.src = data.cover;
          mpCover.style.display = '';
        } else {
          mpCover.removeAttribute('src');
          mpCover.style.display = 'none';
        }
      }
      player.hidden = false;
    }

    function play(d) {
      if (!d || !d.audio) { return; }
      if (currentSrc !== d.audio) {
        currentSrc = d.audio;
        audio.src = d.audio;
      }
      show(d);
      var p = audio.play();
      // 浏览器会拦截未经过用户手势的自动播放，这里只做静默降级
      if (p && typeof p.catch === 'function') {
        p.catch(function () { setIco(false); toast('浏览器阻止了自动播放，请再点一次播放'); });
      }
      setIco(true);
    }

    document.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.mc-play');
      if (!btn) { return; }
      ev.preventDefault();
      ev.stopPropagation();
      var audio2 = btn.getAttribute('data-audio') || '';
      if (currentSrc === audio2 && !audio.paused) {
        audio.pause();
        setIco(false);
        return;
      }
      play({
        audio: audio2,
        name: btn.getAttribute('data-name') || '',
        artist: btn.getAttribute('data-artist') || '',
        cover: btn.getAttribute('data-cover') || ''
      });
    });

    if (mpToggle) {
      mpToggle.addEventListener('click', function () {
        if (audio.paused) {
          var p = audio.play();
          if (p && typeof p.catch === 'function') { p.catch(function () { toast('播放失败，请检查音频地址是否可用'); }); }
          setIco(true);
        } else {
          audio.pause();
          setIco(false);
        }
      });
    }

    if (mpClose) {
      mpClose.addEventListener('click', function () {
        audio.pause();
        audio.removeAttribute('src');
        currentSrc = '';
        player.hidden = true;
        setIco(false);
      });
    }

    audio.addEventListener('play', function () { setIco(true); });
    audio.addEventListener('pause', function () { setIco(false); });
    audio.addEventListener('ended', function () { setIco(false); });
    audio.addEventListener('error', function () {
      setIco(false);
      toast('音频加载失败，可点击卡片前往音乐平台收听');
    });
    audio.addEventListener('timeupdate', function () {
      if (!mpBar || !audio.duration) { return; }
      mpBar.style.width = ((audio.currentTime / audio.duration) * 100).toFixed(2) + '%';
    });
  }());
})();
