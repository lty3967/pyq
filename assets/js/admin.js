/* 后台交互 */
(function () {
  'use strict';
  var CFG = window.WMA || {};

  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }

  /* 后台位于 /admin/ 下，媒体相对路径需回到站点根 */
  function mediaUrl(p) {
    p = String(p || '');
    if (p === '' || /^(https?:)?\/\//i.test(p) || p.charAt(0) === '/') { return p; }
    return '../' + p;
  }

  /* 侧边栏 */
  var side = $('#side'), mask = $('#mask'), mBtn = $('#menuBtn');
  if (mBtn) {
    mBtn.addEventListener('click', function () {
      side.classList.toggle('open');
      mask.classList.toggle('show');
    });
  }
  if (mask) {
    mask.addEventListener('click', function () {
      side.classList.remove('open');
      mask.classList.remove('show');
    });
  }

  /* 危险操作确认 */
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-confirm]');
    if (!el) { return; }
    if (!window.confirm(el.getAttribute('data-confirm'))) {
      ev.preventDefault();
      ev.stopPropagation();
    }
  });

  /* 全选 */
  var checkAll = $('#checkAll');
  if (checkAll) {
    checkAll.addEventListener('change', function () {
      $$('input[name="ids[]"]').forEach(function (c) { c.checked = checkAll.checked; });
    });
  }

  /* 批量操作表单校验 */
  $$('form[data-batch]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      var act = f.querySelector('[name="batch_act"]');
      if (act && act.value === '') { ev.preventDefault(); alert('请选择要执行的操作'); return; }
      var selected = f.id
        ? $$('input[name="ids[]"][form="' + f.id + '"]:checked')
        : $$('input[name="ids[]"]:checked', f);
      if (selected.length === 0) {
        ev.preventDefault();
        alert('请先勾选要操作的项目');
      }
    });
  });

  /* ---------------- 媒体上传器 ---------------- */
  var wrap = $('#uploader');
  if (wrap) {
    var listEl = $('#upList'), inputImg = $('#fileImg'), inputVid = $('#fileVid'), store = $('#mediaData');
    var maxImg = parseInt(wrap.getAttribute('data-max-img') || '9', 10);
    var items = [];
    try { items = JSON.parse(store.value || '[]'); } catch (e) { items = []; }

    function sync() {
      store.value = JSON.stringify(items);
      render();
    }

    function render() {
      listEl.innerHTML = '';
      items.forEach(function (it, i) {
        var d = document.createElement('div');
        d.className = 'up-item';
        if (it.type === 'video') {
          var v = document.createElement('video');
          v.src = mediaUrl(it.path);
          v.muted = true;
          v.preload = 'metadata';
          d.appendChild(v);
          var tag = document.createElement('span');
          tag.className = 'vf';
          tag.textContent = '视频';
          d.appendChild(tag);
        } else {
          var im = document.createElement('img');
          im.src = mediaUrl(it.thumb || it.path);
          im.alt = '';
          d.appendChild(im);
        }
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'del';
        del.textContent = '×';
        del.setAttribute('aria-label', '删除');
        del.addEventListener('click', function () { items.splice(i, 1); sync(); });
        d.appendChild(del);
        listEl.appendChild(d);
      });
      updateBtns();
    }

    function updateBtns() {
      var hasVideo = items.some(function (i) { return i.type === 'video'; });
      var imgCount = items.filter(function (i) { return i.type === 'image'; }).length;
      var bImg = $('#btnImg'), bVid = $('#btnVid');
      if (bImg) { bImg.disabled = hasVideo || imgCount >= maxImg; }
      if (bVid) { bVid.disabled = hasVideo || imgCount > 0; }
      var st = $('#upState');
      if (st) { st.textContent = hasVideo ? '已选择 1 个视频' : ('已选择 ' + imgCount + ' / ' + maxImg + ' 张图片'); }
    }

    function placeholder() {
      var d = document.createElement('div');
      d.className = 'up-item loading';
      d.textContent = '上传中…';
      listEl.appendChild(d);
      return d;
    }

    function upload(file, type, ph) {
      var fd = new FormData();
      fd.append('_token', CFG.token || '');
      fd.append('type', type);
      fd.append('file', file);
      return fetch('upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (ph && ph.parentNode) { ph.parentNode.removeChild(ph); }
          if (!res.ok) { alert(res.msg || '上传失败'); return; }
          items.push(res.data);
          sync();
        }).catch(function () {
          if (ph && ph.parentNode) { ph.parentNode.removeChild(ph); }
          alert('上传请求失败');
        });
    }

    $('#btnImg').addEventListener('click', function () { inputImg.click(); });
    $('#btnVid').addEventListener('click', function () { inputVid.click(); });

    inputImg.addEventListener('change', function () {
      var files = Array.prototype.slice.call(inputImg.files || []);
      var imgCount = items.filter(function (i) { return i.type === 'image'; }).length;
      var room = maxImg - imgCount;
      if (files.length > room) { alert('最多还能再选 ' + room + ' 张图片'); files = files.slice(0, room); }
      var chain = Promise.resolve();
      files.forEach(function (f) {
        chain = chain.then(function () { return upload(f, 'image', placeholder()); });
      });
      chain.then(function () { inputImg.value = ''; });
    });

    inputVid.addEventListener('change', function () {
      var f = (inputVid.files || [])[0];
      if (!f) { return; }
      upload(f, 'video', placeholder()).then(function () { inputVid.value = ''; });
    });

    render();
  }

  /* ---------------- 单图上传（头像 / 封面） ---------------- */
  $$('[data-single-upload]').forEach(function (box) {
    var input = box.querySelector('input[type=file]');
    var target = document.getElementById(box.getAttribute('data-target'));
    var preview = box.querySelector('.sp-img');
    var btn = box.querySelector('.sp-btn');
    var clr = box.querySelector('.sp-clear');
    if (btn && input) { btn.addEventListener('click', function () { input.click(); }); }
    if (clr) {
      clr.addEventListener('click', function () {
        target.value = '';
        if (preview) { preview.removeAttribute('src'); preview.style.display = 'none'; }
      });
    }
    if (!input) { return; }
    input.addEventListener('change', function () {
      var f = (input.files || [])[0];
      if (!f) { return; }
      var fd = new FormData();
      fd.append('_token', CFG.token || '');
      fd.append('type', 'image');
      fd.append('file', f);
      if (btn) { btn.disabled = true; }
      fetch('upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (btn) { btn.disabled = false; }
          input.value = '';
          if (!res.ok) { alert(res.msg || '上传失败'); return; }
          target.value = res.data.path;
          if (preview) { preview.src = mediaUrl(res.data.thumb || res.data.path); preview.style.display = ''; }
        }).catch(function () { if (btn) { btn.disabled = false; } alert('上传请求失败'); });
    });
  });

  /* 字数统计 */
  $$('[data-counter]').forEach(function (ta) {
    var out = document.getElementById(ta.getAttribute('data-counter'));
    if (!out) { return; }
    var upd = function () { out.textContent = ta.value.length + ' / ' + (ta.getAttribute('maxlength') || '∞'); };
    ta.addEventListener('input', upd);
    upd();
  });
})();
