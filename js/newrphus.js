window.newrphus = window.newrphus || function() {
  var options = {};
  var defaultOptions = {
    url: 'example.php',
    callback: function() {
      alert('Thank you! Misprint was sent');
    },
    userId: 0,
    maxLength: 1000,
    minLength: 4
  };

  var ajaxPost = function(url, data, callback) {
    if (window.XMLHttpRequest == undefined) {
      return;
    }

    var request = new XMLHttpRequest();
    request.open('POST', url, true);

    request.onreadystatechange = function() {
      if (this.readyState === 4) {
        if (this.status >= 200 && this.status < 400) {
          var resp = this.responseText;
          if (callback != undefined) {
            callback(resp);
          }
        } else {
          // error callback
        }
      }
    };

    var query = [];
    for (var key in data) {
      query.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
    }

    request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    request.send(query.join('&'));
    request = null;
  }

  var getSelectedText = function() {
    if (window.getSelection) {
        return window.getSelection().toString();
    } else if (document.getSelection) {
        return document.getSelection();
    } else if (document.selection) {
        return document.selection.createRange().text;
    }
  }

  var onKeyPress = function() {
    var e = arguments[0] || window.event;
    var code = e.keyCode ? e.keyCode : (e.which ? e.which : e.charCode);

    if (e.ctrlKey && (code == 13 || code == 10)) {
        sendReport(getSelectedText());
    }
  }

  var sendReport = function(text) {
    if (text !== undefined && text.length <= options.maxLength && text.length >= options.minLength) {
      options.callback();

      ajaxPost(options.url, {misprintText: text, misprintUrl: window.location.href ? window.location.href : '', misprintUserId: options.userId});
    }
  }

  var init = function(opts) {
    options.url = opts.url || defaultOptions.url;
    options.callback = opts.callback || defaultOptions.callback;
    options.userId = opts.userId || defaultOptions.userId;
    options.minLength = opts.minLength || defaultOptions.minLength;
    options.maxLength = opts.maxLength || defaultOptions.maxLength;

    document.onkeypress = onKeyPress;
  }

  return {
    'init': init
  }
}();