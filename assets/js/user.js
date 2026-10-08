(function () {
    'use strict';

    var dataInput = document.getElementById('userMediaData');
    var list = document.getElementById('uploadList');
    var state = document.getElementById('uploadState');
    var imageInput = document.getElementById('userImg');
    var videoInput = document.getElementById('userVid');
    var imageButton = document.getElementById('userImgBtn');
    var videoButton = document.getElementById('userVidBtn');

    if (!dataInput || !list || !state) {
        return;
    }

    var items = [];
    try {
        items = JSON.parse(dataInput.value || '[]');
        if (!Array.isArray(items)) {
            items = [];
        }
    } catch (error) {
        items = [];
    }

    function sync() {
        dataInput.value = JSON.stringify(items);
        render();
    }

    // 用户中心位于 /user/ 下，本地相对路径需回到站点根；云存储地址原样使用
    function mediaUrl(p) {
        p = String(p || '');
        if (p === '' || /^(https?:)?\/\//i.test(p) || p.charAt(0) === '/') { return p; }
        return '../' + p;
    }

    function render() {
        list.innerHTML = '';

        items.forEach(function (item, index) {
            var wrapper = document.createElement('div');
            var removeButton = document.createElement('button');

            wrapper.className = 'upload-item';
            removeButton.type = 'button';
            removeButton.textContent = '×';
            removeButton.setAttribute('aria-label', '移除媒体');
            removeButton.addEventListener('click', function () {
                items.splice(index, 1);
                sync();
            });

            if (item.type === 'video') {
                var video = document.createElement('video');
                video.src = mediaUrl(item.path);
                video.muted = true;
                video.preload = 'metadata';
                wrapper.appendChild(video);
            } else {
                var image = document.createElement('img');
                image.src = mediaUrl(item.thumb || item.path);
                image.alt = '';
                wrapper.appendChild(image);
            }

            wrapper.appendChild(removeButton);
            list.appendChild(wrapper);
        });

        state.textContent = items.length + ' / 9';
        imageButton.disabled = items.some(function (item) {
            return item.type === 'video';
        }) || items.length >= 9;
        videoButton.disabled = items.length > 0;
    }

    function upload(file, type) {
        var formData = new FormData();
        formData.append('_token', window.WM_USER.token);
        formData.append('type', type);
        formData.append('file', file);

        return fetch('upload.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (result) {
                if (!result.ok) {
                    throw new Error(result.msg || '上传失败');
                }
                items.push(result.data);
                sync();
            })
            .catch(function (error) {
                window.alert(error.message);
            });
    }

    imageButton.addEventListener('click', function () {
        imageInput.click();
    });

    videoButton.addEventListener('click', function () {
        videoInput.click();
    });

    imageInput.addEventListener('change', function () {
        var files = Array.prototype.slice.call(imageInput.files || []);
        var room = Math.max(0, 9 - items.length);

        files.slice(0, room).reduce(function (promise, file) {
            return promise.then(function () {
                return upload(file, 'image');
            });
        }, Promise.resolve()).then(function () {
            imageInput.value = '';
        });
    });

    videoInput.addEventListener('change', function () {
        var file = (videoInput.files || [])[0];
        if (!file) {
            return;
        }

        upload(file, 'video').then(function () {
            videoInput.value = '';
        });
    });

    render();

    // 对外暴露清空能力：勾选「分享音乐」时由 music.js 调用
    window.WmUploader = {
        reset: function () {
            items = [];
            sync();
        },
        count: function () {
            return items.length;
        }
    };
}());
