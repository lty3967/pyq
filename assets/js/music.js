/*
 * 分享音乐（发布页通用组件）
 *
 * 后台「发布朋友圈」与用户中心「发布动态」共用同一套交互：
 *   1) 勾选「分享音乐」→ 展开平台 + 音乐 ID/链接输入框；
 *   2) 点「获取信息」→ 后端按平台抓取歌名 / 歌手 / 封面；
 *   3) 抓取失败或字段不全时，在下方手工补填（封面可填图片地址）；
 *   4) 选中音乐后，图片 / 视频上传区会被隐藏并清空已选媒体
 *      （与「分享音乐时不可发布图文和视频」的要求一致，两端都强制）。
 *
 * 选中的音乐以 JSON 写入隐藏域 music_data，提交时由服务端校验并入库；
 * 服务端只信任本站签发的 music.id，不接受客户端伪造的 id。
 */
(function (win, doc) {
  'use strict';

  var state = {
    platform: 'netease',
    on: false,
    data: null
  };

  function el(tag, cls, text) {
    var n = doc.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }

  /* 轻提示：发布页本身没有 toast 容器，这里就地造一个 */
  var toastTimer = null;
  function tip(msg, isErr) {
    var old = doc.getElementById('wmToast');
    if (old) { old.remove(); }
    if (toastTimer) { clearTimeout(toastTimer); }
    var t = el('div', 'wm-toast' + (isErr ? ' err' : ''), msg);
    t.id = 'wmToast';
    doc.body.appendChild(t);
    toastTimer = setTimeout(function () {
      if (t.parentNode) { t.parentNode.removeChild(t); }
    }, 2600);
  }

  function token() {
    if (win.WMA && win.WMA.token) { return win.WMA.token; }
    if (win.WM_USER && win.WM_USER.token) { return win.WM_USER.token; }
    if (win.WM && win.WM.token) { return win.WM.token; }
    return '';
  }

  /**
   * 挂载音乐分享区块
   * @param {Object} opt
   *   mount     挂载容器选择器
   *   api       音乐信息接口地址
   *   platforms 平台字典 {key: {name, ph}}
   *   mediaBox  图片/视频上传区选择器（选中音乐后隐藏并清空）
   */
  function mount(opt) {
    var host = doc.querySelector(opt.mount);
    if (!host) { return; }

    var mediaBox = opt.mediaBox ? doc.querySelector(opt.mediaBox) : null;

    /* ---------- 结构 ---------- */
    var box = el('div', 'music-box');

    var head = el('div', 'mb-head');
    var sw = el('label', 'mb-switch');
    var chk = doc.createElement('input');
    chk.type = 'checkbox';
    chk.id = 'musicOn';
    sw.appendChild(chk);
    sw.appendChild(doc.createTextNode(' 分享音乐'));
    head.appendChild(sw);
    head.appendChild(el('span', 'mb-note', '选中音乐后不可同时发布图片 / 视频'));
    box.appendChild(head);

    var body = el('div', 'mb-body');
    body.hidden = true;

    var row = el('div', 'mb-row');
    var sel = doc.createElement('select');
    sel.id = 'musicPlatform';
    sel.className = 'mb-select';
    Object.keys(opt.platforms).forEach(function (k) {
      var o = doc.createElement('option');
      o.value = k;
      o.textContent = opt.platforms[k].name;
      sel.appendChild(o);
    });
    sel.value = state.platform;

    var inp = doc.createElement('input');
    inp.type = 'text';
    inp.id = 'musicInput';
    inp.className = 'mb-input';
    inp.maxLength = 500;
    inp.placeholder = (opt.platforms[state.platform] || {}).ph || '音乐 ID 或链接';

    var btn = el('button', 'mb-btn', '获取信息');
    btn.type = 'button';
    row.appendChild(sel);
    row.appendChild(inp);
    row.appendChild(btn);
    body.appendChild(row);
    body.appendChild(el('div', 'mb-hint',
      '支持网易云音乐、QQ音乐、酷狗、酷我、Apple Music、Spotify 及其他音乐链接；获取失败可下方手工填写'));

    /* 结果 / 手工填写区 */
    var form = el('div', 'mb-form');
    form.hidden = true;

    var prev = el('div', 'mb-cover');
    var img = doc.createElement('img');
    img.alt = '';
    img.loading = 'lazy';
    var noimg = el('div', 'mb-cover-ph', '无封面');
    prev.appendChild(img);
    prev.appendChild(noimg);
    img.addEventListener('error', function () {
      img.style.display = 'none';
      noimg.style.display = '';
    });
    img.addEventListener('load', function () {
      img.style.display = '';
      noimg.style.display = 'none';
    });

    var fields = el('div', 'mb-fields');
    function field(labelText, id, ph) {
      var w = el('label', 'mb-field');
      w.appendChild(doc.createTextNode(labelText));
      var i = doc.createElement('input');
      i.type = 'text';
      i.id = id;
      i.className = 'mb-input';
      i.maxLength = 500;
      i.placeholder = ph || '';
      w.appendChild(i);
      fields.appendChild(w);
      return i;
    }
    var fName = field('歌曲名', 'musicName', '必填，如：晴天');
    var fArtist = field('歌手', 'musicArtist', '如：周杰伦');
    var fCover = field('封面地址', 'musicCover', '可粘贴图片直链');
    var fUrl = field('歌曲链接', 'musicUrl', '选填，点击卡片跳转');

    var clearBtn = el('button', 'mb-btn ghost', '移除音乐');
    clearBtn.type = 'button';

    form.appendChild(prev);
    form.appendChild(fields);
    form.appendChild(clearBtn);
    body.appendChild(form);
    box.appendChild(body);

    var hidden = doc.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'music_data';
    hidden.id = 'musicData';
    hidden.value = '';
    box.appendChild(hidden);

    host.appendChild(box);

    /* ---------- 行为 ---------- */
    function syncHidden() {
      hidden.value = state.on && state.data ? JSON.stringify(state.data) : '';
    }

    function clearMedia() {
      if (win.WmUploader && typeof win.WmUploader.reset === 'function') {
        win.WmUploader.reset();
      }
      if (mediaBox) { mediaBox.hidden = true; }
    }

    function restoreMedia() {
      if (mediaBox) { mediaBox.hidden = false; }
    }

    function showForm() {
      form.hidden = false;
      var d = state.data || {};
      fName.value = d.song_name || '';
      fArtist.value = d.artist || '';
      fCover.value = d.cover || '';
      fUrl.value = d.url || '';
      if (d.cover) {
        img.src = d.cover;
      } else {
        img.removeAttribute('src');
        img.style.display = 'none';
        noimg.style.display = '';
      }
    }

    function collect() {
      if (!state.data) { state.data = {}; }
      state.data.platform = sel.value;
      state.data.song_name = fName.value.trim();
      state.data.artist = fArtist.value.trim();
      state.data.cover = fCover.value.trim();
      state.data.url = fUrl.value.trim();
      // song_id 只在「获取信息」成功时才有；手工填写时留空，
      // 服务端会用「平台+歌名+歌手」派生一个稳定 ID 用于去重
      syncHidden();
    }

    function turnOn() {
      state.on = true;
      chk.checked = true;
      body.hidden = false;
      clearMedia();
      // 顺序很关键：先把 state.data 回填到输入框，再从输入框同步回隐藏域，
      // 否则刚抓取到的歌名会被空的输入框覆盖掉
      showForm();
      collect();
    }

    function turnOff() {
      state.on = false;
      chk.checked = false;
      body.hidden = true;
      form.hidden = true;
      state.data = null;
      syncHidden();
      restoreMedia();
    }

    chk.addEventListener('change', function () {
      if (chk.checked) {
        turnOn();
        if (fName.value === '') { fName.focus(); }
      } else {
        turnOff();
      }
    });

    sel.addEventListener('change', function () {
      state.platform = sel.value;
      inp.placeholder = (opt.platforms[sel.value] || {}).ph || '音乐 ID 或链接';
    });

    [fName, fArtist, fCover, fUrl].forEach(function (i) {
      i.addEventListener('input', collect);
    });

    clearBtn.addEventListener('click', function () { turnOff(); });

    btn.addEventListener('click', function () {
      var val = inp.value.trim();
      if (!val) { tip('请输入音乐 ID 或链接', true); inp.focus(); return; }
      btn.disabled = true;
      var old = btn.textContent;
      btn.textContent = '获取中…';
      var body2 = new URLSearchParams();
      body2.append('_token', token());
      body2.append('act', 'music');
      body2.append('platform', sel.value);
      body2.append('input', val);
      // 客户端兜底超时：服务端抓外站偶发卡住时，至少给用户一个明确提示
      var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var timer = setTimeout(function () { if (ctrl) { ctrl.abort(); } }, 40000);
      fetch(opt.api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        credentials: 'same-origin',
        body: body2.toString(),
        signal: ctrl.signal
      })
        .then(function (r) {
          // 先取文本再解析：非 JSON（PHP 报错页 / 超时页）也能拿到内容用于提示
          return r.text().then(function (t) {
            var j = null;
            try { j = JSON.parse(t); } catch (e) { j = null; }
            return { j: j, status: r.status, body: t };
          });
        })
        .then(function (res) {
          var j = res.j;
          if (j && j.ok && j.data) {
            state.data = j.data;
            state.platform = j.data.platform || sel.value;
            sel.value = state.platform;
            turnOn();
            tip('已获取：' + (j.data.song_name || ''));
          } else if (j) {
            // 抓取失败不阻断流程：展开手工填写，由站长/用户自己补齐
            state.data = { platform: sel.value, song_id: '' };
            turnOn();
            fName.focus();
            tip(j.msg || '获取失败，请手工填写', true);
          } else {
            state.data = { platform: sel.value, song_id: '' };
            turnOn();
            tip('服务器返回异常（HTTP ' + res.status + '），可手工填写；详情见服务器错误日志', true);
          }
        })
        .catch(function (err) {
          state.data = { platform: sel.value, song_id: '' };
          turnOn();
          tip(err && err.name === 'AbortError'
            ? '请求超时（超过 40 秒），请手工填写'
            : '网络异常，请手工填写', true);
        })
        .then(function () {
          clearTimeout(timer);
          btn.disabled = false;
          btn.textContent = old;
        });
    });

    /* ---------- 编辑回显 ---------- */
    var init = win.WM_MUSIC_INIT || null;
    if (init && init.song_name) {
      state.data = init;
      state.platform = init.platform || 'netease';
      sel.value = state.platform;
      turnOn();
    }
  }

  win.WmMusic = { mount: mount, tip: tip };
}(window, document));
