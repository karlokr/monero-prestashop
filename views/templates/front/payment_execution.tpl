{extends file='page.tpl'}

{block name='page_content'}
<div class="monero-payment-page">
    <h1 class="h1">{l s='Pay with Monero (XMR)' mod='monero'}</h1>

    <div class="monero-payment-box card">
        <div class="card-body">
            {* Status bar — updated by JS *}
            <div id="monero-status" class="monero-status alert {if $monero_status == 'error'}alert-danger{else}alert-info{/if}">
                <p class="mb-0"><strong id="monero-status-text">{$monero_status_message}</strong></p>
            </div>

            {if $monero_status != 'error'}
            <div id="monero-payment-details" class="row">
                {* QR Code *}
                <div class="col-md-4 text-center mb-3">
                    <div id="monero-qrcode" class="monero-qr"></div>
                    <small class="text-muted mt-2 d-block">{l s='Scan with your Monero wallet' mod='monero'}</small>
                </div>

                {* Payment Details *}
                <div class="col-md-8">
                    <div class="monero-detail mb-3">
                        <label class="form-label"><strong>{l s='Amount to send:' mod='monero'}</strong></label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="monero-amount" value="{$monero_amount}" readonly>
                            <span class="input-group-text">XMR</span>
                            <button type="button" class="btn btn-outline-secondary monero-copy-btn" data-copy-target="monero-amount" title="{l s='Copy' mod='monero'}">
                                &#128203;
                            </button>
                        </div>
                    </div>

                    <div class="monero-detail mb-3">
                        <label class="form-label"><strong>{l s='Send to this address:' mod='monero'}</strong></label>
                        <div class="input-group">
                            <input type="text" class="form-control monero-address-input" id="monero-address" value="{$monero_subaddress}" readonly>
                            <button type="button" class="btn btn-outline-secondary monero-copy-btn" data-copy-target="monero-address" title="{l s='Copy' mod='monero'}">
                                &#128203;
                            </button>
                        </div>
                        <small class="text-muted">{l s='This is a unique address generated for your order.' mod='monero'}</small>
                    </div>

                    {* Confirmation progress — hidden until confirming *}
                    <div id="monero-conf-progress" class="mb-3" style="display:none;">
                        <label class="form-label"><strong>{l s='Confirmations:' mod='monero'}</strong></label>
                        <div class="progress">
                            <div id="monero-conf-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%">
                                <span id="monero-conf-text">0 / 0</span>
                            </div>
                        </div>
                    </div>

                    <p class="text-muted">
                        <small>{l s='This page checks for payment every 30 seconds. Do not close this tab.' mod='monero'}</small>
                    </p>
                </div>
            </div>

            {* Receipt box — hidden until paid *}
            <div id="monero-receipt-box" class="card mt-4" style="display:none;">
                <div class="card-header bg-success text-white">
                    <strong>{l s='Payment Receipt' mod='monero'}</strong>
                    <small class="d-block">{l s='Save this — it is the only record linking your order to the payment.' mod='monero'}</small>
                </div>
                <div class="card-body">
                    <pre id="monero-receipt-text" class="mb-2" style="white-space:pre-wrap;word-break:break-all;font-size:0.85em;background:#f8f9fa;padding:1em;border-radius:4px;"></pre>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="monero-copy-receipt">
                        &#128203; {l s='Copy Receipt' mod='monero'}
                    </button>
                </div>
                <div class="card-footer">
                    <div id="monero-overpayment-notice" class="alert alert-warning mb-3" style="display:none;"></div>
                    <a id="monero-continue-btn" href="#" class="btn btn-primary">{l s='Continue →' mod='monero'}</a>
                </div>
            </div>
            {/if}
        </div>
    </div>
</div>

{if $monero_status != 'error'}
<script>
/**
 * Monero ephemeral payment polling.
 * All payment state lives in these JS constants — freed when the tab closes.
 * No cookies, no localStorage, no sessionStorage, no IndexedDB.
 */
(function() {ldelim}
    'use strict';

    // ── Constants from PHP (exist only in this IIFE scope) ──
    var TOKEN        = '{$monero_token|escape:"javascript"}';
    var CALLBACK_URL = '{$monero_callback_url|escape:"javascript"}';
    var URI          = '{$monero_uri|escape:"javascript"}';
    var POLL_MS      = 30000;
    var pollTimer    = null;
    var stopped      = false;

    // ── Local QR Code Generator (no external API calls) ──
    // Minimal QR encoder: generates a QR code entirely in the browser
    // using canvas. No data leaves the client.
    var QR=function(){ldelim}var t={ldelim}L:1,M:0,Q:3,H:2{rdelim},n=function(t,n){ldelim}var e=t,r=n;return{ldelim}getX:function(){ldelim}return e{rdelim},getY:function(){ldelim}return r{rdelim}{rdelim}{rdelim},e=function(t,n,e){ldelim}var r=t,o=n,i=e,a=null,u=null;return a=function(){ldelim}for(var t=new Array(r*o),n=0;n<r*o;n++)t[n]=i;return t{rdelim}(),{ldelim}setBufferItem:function(t,n,e){ldelim}a[n*r+t]=e{rdelim},getBufferItem:function(t,n){ldelim}return a[n*r+t]{rdelim},getWidth:function(){ldelim}return r{rdelim},getHeight:function(){ldelim}return o{rdelim}{rdelim}{rdelim},r=function(t){ldelim}var r=t,o=e(2,1,0),i=n(0,0);return{ldelim}getMode:function(){ldelim}return o{rdelim},getLength:function(){ldelim}return r.length{rdelim},write:function(t){ldelim}for(var n=0;n+2<=r.length;){ldelim}var e=45*a(r.charAt(n))+a(r.charAt(n+1));t.put(e,11),n+=2{rdelim}n<r.length&&t.put(a(r.charAt(n)),6){rdelim}{rdelim};function a(t){ldelim}if("0"<=t&&t<="9")return t.charCodeAt(0)-"0".charCodeAt(0);if("A"<=t&&t<="Z")return t.charCodeAt(0)-"A".charCodeAt(0)+10;switch(t){ldelim}case" ":return 36;case"$":return 37;case"%":return 38;case"*":return 39;case"+":return 40;case"-":return 41;case".":return 42;case"/":return 43;case":":return 44{rdelim}throw"illegal char:"+t{rdelim}{rdelim},o=function(t){ldelim}var n=t;return{ldelim}getMode:function(){ldelim}return e(2,2,0){rdelim},getLength:function(){ldelim}return n.length{rdelim},write:function(t){ldelim}for(var e=0;e<n.length;e++)t.put(n.charCodeAt(e),8){rdelim}{rdelim}{rdelim};function i(){ldelim}var t=0,n=0,e=[];return{ldelim}getBuffer:function(){ldelim}return e{rdelim},getLengthInBits:function(){ldelim}return n{rdelim},put:function(t,e){ldelim}for(var r=0;r<e;r++)this.putBit(1==(t>>>e-r-1&1)){rdelim},putBit:function(r){ldelim}var o=Math.floor(n/8);e.length<=o&&e.push(0),r&&(e[o]|=128>>>n%8),n++{rdelim}{rdelim}{rdelim}function a(t,n){ldelim}for(var e=1,r=0;r<n;r++)e=s(e,t);return e{rdelim}function u(t){ldelim}return a(2,t){rdelim}function s(t,n){ldelim}if(t<1||n<1)throw"invalid arg: "+t+","+n;for(var e=0;t+n>e;e++);for(var r=new Array(e),o=0;o<t;o++)r[o]^=0;for(o=0;o<n;o++)r[o]^=0;return r{rdelim}var c=function(){ldelim}var t=[[1,26,19],[1,26,16],[1,26,13],[1,26,9],[1,44,34],[1,44,28],[1,44,22],[1,44,16],[1,70,55],[1,70,44],[2,35,17],[2,35,13],[1,100,80],[2,50,32],[2,50,24],[4,25,9],[1,134,108],[2,67,43],[2,33,15,2,34,16],[2,33,11,2,34,12],[2,86,68],[4,43,27],[4,43,19],[4,43,15],[2,98,78],[4,49,31],[2,32,14,4,33,15],[4,39,13,1,40,14],[2,121,97],[2,60,38,2,61,39],[4,40,18,2,41,19],[4,40,14,2,41,15],[2,146,116],[3,58,36,2,59,37],[4,36,16,4,37,17],[4,36,12,4,37,13],[2,86,68,2,87,69],[4,69,43,1,70,44],[6,43,19,2,44,20],[6,43,15,2,44,16],[4,101,81],[1,80,50,4,81,51],[4,50,22,4,51,23],[3,36,12,8,37,13],[2,116,92,2,117,93],[6,58,36,2,59,37],[4,46,20,6,47,21],[7,42,14,4,43,15],[4,133,107],[8,59,37,1,60,38],[8,44,20,4,45,21],[12,33,11,4,34,12],[3,145,115,1,146,116],[4,64,40,5,65,41],[11,36,16,5,37,17],[11,36,12,5,37,13],[5,109,87,1,110,88],[5,65,41,5,66,42],[5,54,24,7,55,25],[11,36,12],[5,122,98,1,123,99],[7,73,45,3,74,46],[15,43,19,2,44,20],[3,45,15,13,46,16],[1,135,107,5,136,108],[10,74,46,1,75,47],[1,50,22,15,51,23],[2,42,14,17,43,15],[5,150,120,1,151,121],[9,69,43,4,70,44],[17,50,22,1,51,23],[2,42,14,19,43,15],[3,141,113,4,142,114],[3,70,44,11,71,45],[17,47,21,4,48,22],[9,39,13,16,40,14],[3,135,107,5,136,108],[3,67,41,13,68,42],[15,54,24,5,55,25],[15,43,15,10,44,16],[4,144,116,4,145,117],[17,68,42],[17,50,22,6,51,23],[19,46,16,6,47,17],[2,139,111,7,140,112],[17,74,46],[7,54,24,16,55,25],[34,37,13],[4,151,121,5,152,122],[4,75,47,14,76,48],[11,54,24,14,55,25],[16,45,15,14,46,16],[6,147,117,4,148,118],[6,73,45,14,74,46],[11,54,24,16,55,25],[30,46,16,2,47,17],[8,132,106,4,133,107],[8,75,47,13,76,48],[7,54,24,22,55,25],[22,45,15,13,46,16],[10,142,114,2,143,115],[19,74,46,4,75,47],[28,50,22,6,51,23],[33,46,16,4,47,17],[8,152,122,4,153,123],[22,73,45,3,74,46],[8,53,23,26,54,24],[12,45,15,28,46,16],[3,147,117,10,148,118],[3,73,45,23,74,46],[4,54,24,31,55,25],[11,45,15,31,46,16],[7,146,116,7,147,117],[21,73,45,7,74,46],[1,53,23,37,54,24],[19,45,15,26,46,16],[5,145,115,10,146,116],[19,75,47,10,76,48],[15,54,24,25,55,25],[23,45,15,25,46,16],[13,145,115,3,146,116],[2,74,46,29,75,47],[42,54,24,1,55,25],[23,45,15,28,46,16],[17,145,115],[10,74,46,23,75,47],[10,54,24,35,55,25],[19,45,15,35,46,16],[17,145,115,1,146,116],[14,74,46,21,75,47],[29,54,24,19,55,25],[11,45,15,46,46,16],[13,145,115,6,146,116],[14,74,46,23,75,47],[44,54,24,7,55,25],[59,46,16,1,47,17],[12,151,121,7,152,122],[12,75,47,26,76,48],[39,54,24,14,55,25],[22,45,15,41,46,16],[6,151,121,14,152,122],[6,75,47,34,76,48],[46,54,24,10,55,25],[2,45,15,64,46,16],[17,152,122,4,153,123],[29,74,46,14,75,47],[49,54,24,10,55,25],[24,45,15,46,46,16],[4,152,122,18,153,123],[13,74,46,32,75,47],[48,54,24,14,55,25],[42,45,15,32,46,16],[20,147,117,4,148,118],[40,75,47,7,76,48],[43,54,24,22,55,25],[10,45,15,67,46,16],[19,148,118,6,149,119],[18,75,47,31,76,48],[34,54,24,34,55,25],[20,45,15,61,46,16]];return{ldelim}getRSBlocks:function(n,e){ldelim}var r=function(n,e){ldelim}switch(e){ldelim}case t.L:return n[0];case t.M:return n[1];case t.Q:return n[2];case t.H:return n[3]{rdelim}{rdelim}(n-1,e);if(void 0===r)throw"bad rs block @ typeNumber:"+n+"/errorCorrectionLevel:"+e;for(var o=[],i=0;i<r.length;i+=3)for(var a=r[i],u=r[i+1],s=r[i+2],c=0;c<a;c++)o.push({ldelim}dataCount:s,totalCount:u{rdelim});return o{rdelim}{rdelim}{rdelim}();function f(n,r){ldelim}var o=n,s=r,f=null,l=0,d=null,h=[],g=0,p=0,v=null,m=[];!function(){ldelim}for(var t=function(t){ldelim}for(var n=0,e=0;e<t.length;e++)n+=t[e].totalCount;return n{rdelim}(c.getRSBlocks(o,s)),n=i(),e=0;e<h.length;e++){ldelim}var r=h[e];n.put(r.getMode().getBufferItem(0,0)?4:4,4),n.put(r.getLength(),8),r.write(n){rdelim}for(;n.getLengthInBits()>8*t;)throw"code length overflow";for(n.getLengthInBits()%8!=0&&n.put(0,8-n.getLengthInBits()%8);!(n.getLengthInBits()>=8*t);){ldelim}n.put(236,8);if(n.getLengthInBits()>=8*t)break;n.put(17,8){rdelim}var a=n.getBuffer();f=a,l=8*t{rdelim}();var y=4*o+17;function w(t){ldelim}for(var n=e(y,y,null),r=0;r<y;r++)for(var a=0;a<y;a++){ldelim}var u=!1;0<=d&&(d>>a&1)==1&&(u=!0);var s=n.getBufferItem(a,r);null===s?u&&n.setBufferItem(a,r,1):n.setBufferItem(a,r,s?1:0){rdelim}return n{rdelim}return d=function(){ldelim}for(var n=0,e=0;e<8;e++){ldelim}var r=w(e);void 0===v&&(v=r);var o=function(t){ldelim}for(var n=0,e=0;e<t.getWidth();e++)for(var r=0;r<t.getHeight();r++){ldelim}var o=t.getBufferItem(e,r);null!==o&&(1===o&&n++){rdelim}return n{rdelim}(r);(0===e||o<n)&&(n=o,d=e){rdelim}return d{rdelim}(),v=w(d),{ldelim}getModuleCount:function(){ldelim}return y{rdelim},isDark:function(t,n){ldelim}return 1===v.getBufferItem(n,t){rdelim}{rdelim}{rdelim}function l(t){ldelim}for(var n=0,r=0;r<t.length;r++)n+=t[r].length;for(var o=new Array(n),i=0,a=0;a<t.length;a++)for(var u=0;u<t[a].length;u++)o[i++]=t[a][u];return o{rdelim}return{ldelim}createQR:function(t,n){ldelim}n=n||"L";for(var e=[],a=o(t),u=1;u<=40;u++){ldelim}var s=c.getRSBlocks(u,{ldelim}L:1,M:0,Q:3,H:2{rdelim}[n]||1),d=0;for(var h=0;h<s.length;h++)d+=s[h].dataCount;if(a.getLength()<=d){ldelim}e.push(a);break{rdelim}{rdelim}if(0===e.length)return null;var g=u;return function(t,n){ldelim}var e=f(t,{ldelim}L:1,M:0,Q:3,H:2{rdelim}[n]||1);return e{rdelim}(g,n){rdelim}{rdelim}{rdelim}();
    // Render QR to canvas — no data leaves the browser
    var qrEl = document.getElementById('monero-qrcode');
    if (qrEl && URI) {ldelim}
        try {ldelim}
            moneroRenderQR(qrEl, URI, 200);
        {rdelim} catch(e) {ldelim}
            qrEl.textContent = URI;
        {rdelim}
    {rdelim}

    /**
     * Render a QR code onto a canvas element inside the given container.
     *
     * Encodes the data string into QR modules, creates a <canvas>,
     * and draws black/white cells. Falls back to displaying the
     * raw data string if encoding fails.
     *
     * @param {HTMLElement} container - DOM element to append the canvas to
     * @param {string}      data     - String to encode (e.g., monero: URI)
     * @param {number}      size     - Canvas width/height in pixels
     */
    function moneroRenderQR(container, data, size) {ldelim}
        // Encode to byte-mode QR
        var modules = generateQRModules(data);
        if (!modules) {ldelim} container.textContent = data; return; {rdelim}
        var count = modules.getModuleCount();
        var canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        var ctx = canvas.getContext('2d');
        var cellSize = size / count;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, size, size);
        ctx.fillStyle = '#000000';
        for (var r = 0; r < count; r++) {ldelim}
            for (var c = 0; c < count; c++) {ldelim}
                if (modules.isDark(r, c)) {ldelim}
                    ctx.fillRect(c * cellSize, r * cellSize, cellSize, cellSize);
                {rdelim}
            {rdelim}
        {rdelim}
        canvas.style.maxWidth = size + 'px';
        canvas.style.width = '100%';
        canvas.style.height = 'auto';
        canvas.alt = 'Monero Payment QR Code';
        container.appendChild(canvas);
    {rdelim}

    /**
     * Attempt to generate QR module data for the given string.
     *
     * Tries QR versions 1 through 40 (increasing capacity) until
     * the data fits. Returns the QR module grid object or null.
     *
     * @param {string} data - The string to encode into a QR code
     * @returns {Object|null} QR module grid with getModuleCount() and isDark(row, col), or null
     */
    function generateQRModules(data) {ldelim}
        // Try byte-mode encoding, auto-detect version (1-40)
        for (var ver = 1; ver <= 40; ver++) {ldelim}
            try {ldelim}
                var qr = makeQR(ver, 1, data); // errorCorrectionLevel L=1
                return qr;
            {rdelim} catch(e) {ldelim} continue; {rdelim}
        {rdelim}
        return null;
    {rdelim}

    /**
     * Build a complete QR code module grid for a specific version and error correction level.
     *
     * Implements the full QR encoding pipeline: data encoding (byte mode),
     * error correction (Reed-Solomon), module placement (finder patterns,
     * alignment patterns, timing patterns, format/version info), data
     * masking with penalty scoring, and best-mask selection.
     *
     * @param {number} typeNumber            - QR version (1-40)
     * @param {number} errorCorrectionLevel  - EC level: 1=L, 0=M, 3=Q, 2=H
     * @param {string} data                  - Raw string to encode
     * @returns {Object} QR grid with getModuleCount() and isDark(row, col)
     * @throws {string} If data exceeds capacity for the given version/EC level
     */
    function makeQR(typeNumber, errorCorrectionLevel, data) {ldelim}
        var PAD0 = 0xEC, PAD1 = 0x11;
        var modules = null, moduleCount = 0, dataCache = null;
        moduleCount = typeNumber * 4 + 17;

        function makeImpl(test, maskPattern) {ldelim}
            modules = [];
            for (var row = 0; row < moduleCount; row++) {ldelim}
                modules[row] = [];
                for (var col = 0; col < moduleCount; col++) modules[row][col] = null;
            {rdelim}
            setupPositionProbePattern(0, 0);
            setupPositionProbePattern(moduleCount - 7, 0);
            setupPositionProbePattern(0, moduleCount - 7);
            setupPositionAdjustPattern();
            setupTimingPattern();
            setupTypeInfo(test, maskPattern);
            if (typeNumber >= 7) setupTypeNumber(test);
            if (dataCache === null) dataCache = createData(typeNumber, errorCorrectionLevel, [{ldelim} mode: 4, data: data {rdelim}]);
            mapData(dataCache, maskPattern);
        {rdelim}

        function setupPositionProbePattern(row, col) {ldelim}
            for (var r = -1; r <= 7; r++) {ldelim}
                if (row + r <= -1 || moduleCount <= row + r) continue;
                for (var c = -1; c <= 7; c++) {ldelim}
                    if (col + c <= -1 || moduleCount <= col + c) continue;
                    if ((0 <= r && r <= 6 && (c === 0 || c === 6)) || (0 <= c && c <= 6 && (r === 0 || r === 6)) || (2 <= r && r <= 4 && 2 <= c && c <= 4))
                        modules[row + r][col + c] = true;
                    else modules[row + r][col + c] = false;
                {rdelim}
            {rdelim}
        {rdelim}

        var PATTERN_POSITION_TABLE = [[], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50], [6, 30, 54], [6, 32, 58], [6, 34, 62], [6, 26, 46, 66], [6, 26, 48, 70], [6, 26, 50, 74], [6, 30, 54, 78], [6, 30, 56, 82], [6, 30, 58, 86], [6, 34, 62, 90], [6, 28, 50, 72, 94], [6, 26, 50, 74, 98], [6, 30, 54, 78, 102], [6, 28, 54, 80, 106], [6, 32, 58, 84, 110], [6, 30, 58, 86, 114], [6, 34, 62, 90, 118], [6, 26, 50, 74, 98, 122], [6, 30, 54, 78, 102, 126], [6, 26, 52, 78, 104, 130], [6, 30, 56, 82, 108, 134], [6, 34, 60, 86, 112, 138], [6, 30, 58, 86, 114, 142], [6, 34, 62, 90, 118, 146], [6, 30, 54, 78, 102, 126, 150], [6, 24, 50, 76, 102, 128, 154], [6, 28, 54, 80, 106, 132, 158], [6, 32, 58, 84, 110, 136, 162], [6, 26, 54, 82, 110, 138, 166], [6, 30, 58, 86, 114, 142, 170]];

        function setupPositionAdjustPattern() {ldelim}
            var pos = PATTERN_POSITION_TABLE[typeNumber - 1];
            if (!pos) return;
            for (var i = 0; i < pos.length; i++) {ldelim}
                for (var j = 0; j < pos.length; j++) {ldelim}
                    var row = pos[i], col = pos[j];
                    if (modules[row][col] !== null) continue;
                    for (var r = -2; r <= 2; r++) for (var c = -2; c <= 2; c++)
                        modules[row + r][col + c] = (r === -2 || r === 2 || c === -2 || c === 2 || (r === 0 && c === 0)) ? true : false;
                {rdelim}
            {rdelim}
        {rdelim}

        function setupTimingPattern() {ldelim}
            for (var r = 8; r < moduleCount - 8; r++) {ldelim}
                if (modules[r][6] !== null) continue;
                modules[r][6] = (r % 2 === 0);
            {rdelim}
            for (var c = 8; c < moduleCount - 8; c++) {ldelim}
                if (modules[6][c] !== null) continue;
                modules[6][c] = (c % 2 === 0);
            {rdelim}
        {rdelim}

        function setupTypeInfo(test, maskPattern) {ldelim}
            var data = (errorCorrectionLevel << 3) | maskPattern;
            var bits = QRUtil.getBCHTypeInfo(data);
            for (var i = 0; i < 15; i++) {ldelim}
                var mod = (!test && ((bits >> i) & 1) === 1);
                if (i < 6) modules[i][8] = mod;
                else if (i < 8) modules[i + 1][8] = mod;
                else modules[moduleCount - 15 + i][8] = mod;
            {rdelim}
            for (var i = 0; i < 15; i++) {ldelim}
                var mod = (!test && ((bits >> i) & 1) === 1);
                if (i < 8) modules[8][moduleCount - i - 1] = mod;
                else if (i < 9) modules[8][15 - i - 1 + 1] = mod;
                else modules[8][15 - i - 1] = mod;
            {rdelim}
            modules[moduleCount - 8][8] = !test;
        {rdelim}

        function setupTypeNumber(test) {ldelim}
            var bits = QRUtil.getBCHTypeNumber(typeNumber);
            for (var i = 0; i < 18; i++) {ldelim}
                var mod = (!test && ((bits >> i) & 1) === 1);
                modules[Math.floor(i / 3)][i % 3 + moduleCount - 8 - 3] = mod;
            {rdelim}
            for (var i = 0; i < 18; i++) {ldelim}
                var mod = (!test && ((bits >> i) & 1) === 1);
                modules[i % 3 + moduleCount - 8 - 3][Math.floor(i / 3)] = mod;
            {rdelim}
        {rdelim}

        function mapData(data, maskPattern) {ldelim}
            var inc = -1, row = moduleCount - 1, bitIndex = 7, byteIndex = 0;
            for (var col = moduleCount - 1; col > 0; col -= 2) {ldelim}
                if (col === 6) col--;
                while (true) {ldelim}
                    for (var c = 0; c < 2; c++) {ldelim}
                        if (modules[row][col - c] === null) {ldelim}
                            var dark = false;
                            if (byteIndex < data.length) dark = ((data[byteIndex] >>> bitIndex & 1) === 1);
                            if (QRUtil.getMask(maskPattern, row, col - c)) dark = !dark;
                            modules[row][col - c] = dark;
                            bitIndex--;
                            if (bitIndex === -1) {ldelim} byteIndex++; bitIndex = 7; {rdelim}
                        {rdelim}
                    {rdelim}
                    row += inc;
                    if (row < 0 || moduleCount <= row) {ldelim} row -= inc; inc = -inc; break; {rdelim}
                {rdelim}
            {rdelim}
        {rdelim}

        var QRUtil = {ldelim}
            PATTERN_POSITION_TABLE: PATTERN_POSITION_TABLE,
            G15: (1 << 10) | (1 << 8) | (1 << 5) | (1 << 4) | (1 << 2) | (1 << 1) | (1 << 0),
            G18: (1 << 12) | (1 << 11) | (1 << 10) | (1 << 9) | (1 << 8) | (1 << 5) | (1 << 2) | (1 << 0),
            G15_MASK: (1 << 14) | (1 << 12) | (1 << 10) | (1 << 4) | (1 << 1),
            getBCHTypeInfo: function(data) {ldelim}
                var d = data << 10;
                while (QRUtil.getBCHDigit(d) - QRUtil.getBCHDigit(QRUtil.G15) >= 0)
                    d ^= (QRUtil.G15 << (QRUtil.getBCHDigit(d) - QRUtil.getBCHDigit(QRUtil.G15)));
                return ((data << 10) | d) ^ QRUtil.G15_MASK;
            {rdelim},
            getBCHTypeNumber: function(data) {ldelim}
                var d = data << 12;
                while (QRUtil.getBCHDigit(d) - QRUtil.getBCHDigit(QRUtil.G18) >= 0)
                    d ^= (QRUtil.G18 << (QRUtil.getBCHDigit(d) - QRUtil.getBCHDigit(QRUtil.G18)));
                return (data << 12) | d;
            {rdelim},
            getBCHDigit: function(data) {ldelim}
                var digit = 0;
                while (data !== 0) {ldelim} digit++; data >>>= 1; {rdelim}
                return digit;
            {rdelim},
            getMask: function(maskPattern, i, j) {ldelim}
                switch (maskPattern) {ldelim}
                    case 0: return (i + j) % 2 === 0;
                    case 1: return i % 2 === 0;
                    case 2: return j % 3 === 0;
                    case 3: return (i + j) % 3 === 0;
                    case 4: return (Math.floor(i / 2) + Math.floor(j / 3)) % 2 === 0;
                    case 5: return (i * j) % 2 + (i * j) % 3 === 0;
                    case 6: return ((i * j) % 2 + (i * j) % 3) % 2 === 0;
                    case 7: return ((i * j) % 3 + (i + j) % 2) % 2 === 0;
                {rdelim}
            {rdelim}
        {rdelim};

        function createData(typeNumber, errorCorrectionLevel, dataList) {ldelim}
            var rsBlocks = QRRSBlock.getRSBlocks(typeNumber, errorCorrectionLevel);
            var buffer = qrBitBuffer();
            for (var i = 0; i < dataList.length; i++) {ldelim}
                var d = dataList[i];
                buffer.put(d.mode, 4);
                buffer.put(d.data.length, QRUtil.getLengthInBits(d.mode, typeNumber));
                for (var j = 0; j < d.data.length; j++) buffer.put(d.data.charCodeAt(j), 8);
            {rdelim}
            var totalDataCount = 0;
            for (var i = 0; i < rsBlocks.length; i++) totalDataCount += rsBlocks[i].dataCount;
            if (buffer.getLengthInBits() > totalDataCount * 8) throw 'code length overflow';
            if (buffer.getLengthInBits() + 4 <= totalDataCount * 8) buffer.put(0, 4);
            while (buffer.getLengthInBits() % 8 !== 0) buffer.putBit(false);
            while (true) {ldelim}
                if (buffer.getLengthInBits() >= totalDataCount * 8) break;
                buffer.put(PAD0, 8);
                if (buffer.getLengthInBits() >= totalDataCount * 8) break;
                buffer.put(PAD1, 8);
            {rdelim}
            return createBytes(buffer, rsBlocks);
        {rdelim}

        QRUtil.getLengthInBits = function(mode, type) {ldelim}
            if (1 <= type && type < 10) return mode === 4 ? 8 : 0;
            else if (type < 27) return mode === 4 ? 16 : 0;
            else if (type < 41) return mode === 4 ? 16 : 0;
            throw 'type:' + type;
        {rdelim};

        function createBytes(buffer, rsBlocks) {ldelim}
            var offset = 0, maxDcCount = 0, maxEcCount = 0;
            var dcdata = new Array(rsBlocks.length), ecdata = new Array(rsBlocks.length);
            for (var r = 0; r < rsBlocks.length; r++) {ldelim}
                var dcCount = rsBlocks[r].dataCount, ecCount = rsBlocks[r].totalCount - dcCount;
                maxDcCount = Math.max(maxDcCount, dcCount);
                maxEcCount = Math.max(maxEcCount, ecCount);
                dcdata[r] = new Array(dcCount);
                for (var i = 0; i < dcdata[r].length; i++) dcdata[r][i] = 0xff & buffer.getByte(i + offset);
                offset += dcCount;
                var rsPoly = QRUtil.getErrorCorrectPolynomial(ecCount);
                var rawPoly = qrPolynomial(dcdata[r], rsPoly.getLength() - 1);
                var modPoly = rawPoly.mod(rsPoly);
                ecdata[r] = new Array(rsPoly.getLength() - 1);
                for (var i = 0; i < ecdata[r].length; i++) {ldelim}
                    var modIndex = i + modPoly.getLength() - ecdata[r].length;
                    ecdata[r][i] = (modIndex >= 0) ? modPoly.get(modIndex) : 0;
                {rdelim}
            {rdelim}
            var totalCodeCount = 0;
            for (var i = 0; i < rsBlocks.length; i++) totalCodeCount += rsBlocks[i].totalCount;
            var data = new Array(totalCodeCount), index = 0;
            for (var i = 0; i < maxDcCount; i++) for (var r = 0; r < rsBlocks.length; r++) if (i < dcdata[r].length) data[index++] = dcdata[r][i];
            for (var i = 0; i < maxEcCount; i++) for (var r = 0; r < rsBlocks.length; r++) if (i < ecdata[r].length) data[index++] = ecdata[r][i];
            return data;
        {rdelim}

        QRUtil.getErrorCorrectPolynomial = function(errorCorrectLength) {ldelim}
            var a = qrPolynomial([1], 0);
            for (var i = 0; i < errorCorrectLength; i++) a = a.multiply(qrPolynomial([1, QRMath.gexp(i)], 0));
            return a;
        {rdelim};

        var QRMath = {ldelim}
            EXP_TABLE: new Array(256), LOG_TABLE: new Array(256),
            glog: function(n) {ldelim} if (n < 1) throw 'glog(' + n + ')'; return QRMath.LOG_TABLE[n]; {rdelim},
            gexp: function(n) {ldelim} while (n < 0) n += 255; while (n >= 256) n -= 255; return QRMath.EXP_TABLE[n]; {rdelim}
        {rdelim};
        for (var qi = 0; qi < 8; qi++) QRMath.EXP_TABLE[qi] = 1 << qi;
        for (var qi = 8; qi < 256; qi++) QRMath.EXP_TABLE[qi] = QRMath.EXP_TABLE[qi - 4] ^ QRMath.EXP_TABLE[qi - 5] ^ QRMath.EXP_TABLE[qi - 6] ^ QRMath.EXP_TABLE[qi - 8];
        for (var qi = 0; qi < 255; qi++) QRMath.LOG_TABLE[QRMath.EXP_TABLE[qi]] = qi;

        function qrPolynomial(num, shift) {ldelim}
            var _num = (function() {ldelim}
                var offset = 0;
                while (offset < num.length && num[offset] === 0) offset++;
                var _num = new Array(num.length - offset + shift);
                for (var i = 0; i < num.length - offset; i++) _num[i] = num[i + offset];
                return _num;
            {rdelim})();
            return {ldelim}
                get: function(index) {ldelim} return _num[index]; {rdelim},
                getLength: function() {ldelim} return _num.length; {rdelim},
                multiply: function(e) {ldelim}
                    var num2 = new Array(_num.length + e.getLength() - 1);
                    for (var i = 0; i < _num.length; i++) for (var j = 0; j < e.getLength(); j++)
                        num2[i + j] ^= QRMath.gexp(QRMath.glog(_num[i]) + QRMath.glog(e.get(j)));
                    return qrPolynomial(num2, 0);
                {rdelim},
                mod: function(e) {ldelim}
                    if (_num.length - e.getLength() < 0) return qrPolynomial(_num, 0);
                    var ratio = QRMath.glog(_num[0]) - QRMath.glog(e.get(0));
                    var num2 = new Array(_num.length);
                    for (var i = 0; i < _num.length; i++) num2[i] = _num[i];
                    for (var i = 0; i < e.getLength(); i++) num2[i] ^= QRMath.gexp(QRMath.glog(e.get(i)) + ratio);
                    return qrPolynomial(num2, 0).mod(e);
                {rdelim}
            {rdelim};
        {rdelim}

        var QRRSBlock = {ldelim}
            RS_BLOCK_TABLE: [[1,26,19],[1,26,16],[1,26,13],[1,26,9],[1,44,34],[1,44,28],[1,44,22],[1,44,16],[1,70,55],[1,70,44],[2,35,17],[2,35,13],[1,100,80],[2,50,32],[2,50,24],[4,25,9],[1,134,108],[2,67,43],[2,33,15,2,34,16],[2,33,11,2,34,12],[2,86,68],[4,43,27],[4,43,19],[4,43,15],[2,98,78],[4,49,31],[2,32,14,4,33,15],[4,39,13,1,40,14],[2,121,97],[2,60,38,2,61,39],[4,40,18,2,41,19],[4,40,14,2,41,15],[2,146,116],[3,58,36,2,59,37],[4,36,16,4,37,17],[4,36,12,4,37,13],[2,86,68,2,87,69],[4,69,43,1,70,44],[6,43,19,2,44,20],[6,43,15,2,44,16],[4,101,81],[1,80,50,4,81,51],[4,50,22,4,51,23],[3,36,12,8,37,13],[2,116,92,2,117,93],[6,58,36,2,59,37],[4,46,20,6,47,21],[7,42,14,4,43,15],[4,133,107],[8,59,37,1,60,38],[8,44,20,4,45,21],[12,33,11,4,34,12],[3,145,115,1,146,116],[4,64,40,5,65,41],[11,36,16,5,37,17],[11,36,12,5,37,13],[5,109,87,1,110,88],[5,65,41,5,66,42],[5,54,24,7,55,25],[11,36,12],[5,122,98,1,123,99],[7,73,45,3,74,46],[15,43,19,2,44,20],[3,45,15,13,46,16],[1,135,107,5,136,108],[10,74,46,1,75,47],[1,50,22,15,51,23],[2,42,14,17,43,15],[5,150,120,1,151,121],[9,69,43,4,70,44],[17,50,22,1,51,23],[2,42,14,19,43,15],[3,141,113,4,142,114],[3,70,44,11,71,45],[17,47,21,4,48,22],[9,39,13,16,40,14],[3,135,107,5,136,108],[3,67,41,13,68,42],[15,54,24,5,55,25],[15,43,15,10,44,16],[4,144,116,4,145,117],[17,68,42],[17,50,22,6,51,23],[19,46,16,6,47,17],[2,139,111,7,140,112],[17,74,46],[7,54,24,16,55,25],[34,37,13],[4,151,121,5,152,122],[4,75,47,14,76,48],[11,54,24,14,55,25],[16,45,15,14,46,16],[6,147,117,4,148,118],[6,73,45,14,74,46],[11,54,24,16,55,25],[30,46,16,2,47,17],[8,132,106,4,133,107],[8,75,47,13,76,48],[7,54,24,22,55,25],[22,45,15,13,46,16],[10,142,114,2,143,115],[19,74,46,4,75,47],[28,50,22,6,51,23],[33,46,16,4,47,17],[8,152,122,4,153,123],[22,73,45,3,74,46],[8,53,23,26,54,24],[12,45,15,28,46,16],[3,147,117,10,148,118],[3,73,45,23,74,46],[4,54,24,31,55,25],[11,45,15,31,46,16],[7,146,116,7,147,117],[21,73,45,7,74,46],[1,53,23,37,54,24],[19,45,15,26,46,16],[5,145,115,10,146,116],[19,75,47,10,76,48],[15,54,24,25,55,25],[23,45,15,25,46,16],[13,145,115,3,146,116],[2,74,46,29,75,47],[42,54,24,1,55,25],[23,45,15,28,46,16],[17,145,115],[10,74,46,23,75,47],[10,54,24,35,55,25],[19,45,15,35,46,16],[17,145,115,1,146,116],[14,74,46,21,75,47],[29,54,24,19,55,25],[11,45,15,46,46,16],[13,145,115,6,146,116],[14,74,46,23,75,47],[44,54,24,7,55,25],[59,46,16,1,47,17],[12,151,121,7,152,122],[12,75,47,26,76,48],[39,54,24,14,55,25],[22,45,15,41,46,16],[6,151,121,14,152,122],[6,75,47,34,76,48],[46,54,24,10,55,25],[2,45,15,64,46,16],[17,152,122,4,153,123],[29,74,46,14,75,47],[49,54,24,10,55,25],[24,45,15,46,46,16],[4,152,122,18,153,123],[13,74,46,32,75,47],[48,54,24,14,55,25],[42,45,15,32,46,16],[20,147,117,4,148,118],[40,75,47,7,76,48],[43,54,24,22,55,25],[10,45,15,67,46,16],[19,148,118,6,149,119],[18,75,47,31,76,48],[34,54,24,34,55,25],[20,45,15,61,46,16]],
            getRSBlocks: function(typeNumber, errorCorrectionLevel) {ldelim}
                var rsBlock = QRRSBlock.RS_BLOCK_TABLE[(typeNumber - 1) * 4 + errorCorrectionLevel];
                if (typeof rsBlock === 'undefined') throw 'bad rs block @ typeNumber:' + typeNumber + '/errorCorrectionLevel:' + errorCorrectionLevel;
                var list = [];
                for (var i = 0; i < rsBlock.length; i += 3)
                    for (var j = 0; j < rsBlock[i]; j++)
                        list.push({ldelim} totalCount: rsBlock[i + 1], dataCount: rsBlock[i + 2] {rdelim});
                return list;
            {rdelim}
        {rdelim};

        function qrBitBuffer() {ldelim}
            var _buffer = [], _length = 0;
            return {ldelim}
                getByte: function(index) {ldelim} return _buffer[Math.floor(index / 8)] === undefined ? 0 : _buffer[Math.floor(index / 8)]; {rdelim},
                put: function(num, length) {ldelim} for (var i = 0; i < length; i++) this.putBit(((num >>> (length - i - 1)) & 1) === 1); {rdelim},
                getLengthInBits: function() {ldelim} return _length; {rdelim},
                putBit: function(bit) {ldelim}
                    var bufIndex = Math.floor(_length / 8);
                    if (_buffer.length <= bufIndex) _buffer.push(0);
                    if (bit) _buffer[bufIndex] |= (0x80 >>> (_length % 8));
                    _length++;
                {rdelim}
            {rdelim};
        {rdelim}

        // Find best mask
        var minLostPoint = 0, bestPattern = 0;
        for (var i = 0; i < 8; i++) {ldelim}
            makeImpl(true, i);
            var lostPoint = 0;
            // Penalty 1: groups of same color in row/col
            for (var row = 0; row < moduleCount; row++) {ldelim}
                for (var col = 0; col < moduleCount; col++) {ldelim}
                    var sameCount = 0, dark = modules[row][col];
                    for (var r = -1; r <= 1; r++) {ldelim}
                        if (row + r < 0 || moduleCount <= row + r) continue;
                        for (var c = -1; c <= 1; c++) {ldelim}
                            if (col + c < 0 || moduleCount <= col + c) continue;
                            if (r === 0 && c === 0) continue;
                            if (dark === modules[row + r][col + c]) sameCount++;
                        {rdelim}
                    {rdelim}
                    if (sameCount > 5) lostPoint += (3 + sameCount - 5);
                {rdelim}
            {rdelim}
            if (i === 0 || lostPoint < minLostPoint) {ldelim} minLostPoint = lostPoint; bestPattern = i; {rdelim}
        {rdelim}

        makeImpl(false, bestPattern);

        return {ldelim}
            getModuleCount: function() {ldelim} return moduleCount; {rdelim},
            isDark: function(row, col) {ldelim}
                if (row < 0 || moduleCount <= row || col < 0 || moduleCount <= col) throw row + ',' + col;
                return modules[row][col];
            {rdelim}
        {rdelim};
    {rdelim}

    // ── Clipboard helpers (delegated) ──

    /**
     * Delegated click handler for copy buttons.
     *
     * Listens for clicks on elements with class '.monero-copy-btn',
     * reads the 'data-copy-target' attribute to find the input element,
     * selects its content, and copies to clipboard via the Clipboard API
     * (with execCommand fallback).
     */
    document.addEventListener('click', function(e) {ldelim}
        var btn = e.target.closest('.monero-copy-btn');
        if (!btn) return;
        var targetId = btn.getAttribute('data-copy-target');
        var el = document.getElementById(targetId);
        if (el) {ldelim}
            el.select();
            el.setSelectionRange(0, 99999);
            try {ldelim} navigator.clipboard.writeText(el.value); {rdelim}
            catch(ex) {ldelim} document.execCommand('copy'); {rdelim}
        {rdelim}
    {rdelim});

    /**
     * Copy the payment receipt text to clipboard.
     *
     * Reads the textContent of #monero-receipt-text and writes it to
     * the clipboard via navigator.clipboard.writeText(), falling back
     * to a temporary textarea + execCommand('copy') if the API is unavailable.
     */
    document.getElementById('monero-copy-receipt').addEventListener('click', function() {ldelim}
        var text = document.getElementById('monero-receipt-text').textContent;
        try {ldelim} navigator.clipboard.writeText(text); {rdelim}
        catch(ex) {ldelim}
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        {rdelim}
    {rdelim});

    // ── UI updaters ──

    /**
     * Update the status alert banner with a Bootstrap alert class and message.
     *
     * @param {string} cls - Bootstrap alert class suffix (e.g., 'info', 'warning', 'success', 'danger')
     * @param {string} msg - Status message text to display
     */
    function setStatus(cls, msg) {ldelim}
        var el = document.getElementById('monero-status');
        el.className = 'monero-status alert alert-' + cls;
        document.getElementById('monero-status-text').textContent = msg;
    {rdelim}

    /**
     * Show and update the confirmation progress bar.
     *
     * Makes the progress bar container visible, calculates the completion
     * percentage, and updates the bar width and label text.
     *
     * @param {number} current  - Number of confirmations received so far
     * @param {number} required - Total confirmations required for payment acceptance
     */
    function showConfirmations(current, required) {ldelim}
        var wrap = document.getElementById('monero-conf-progress');
        wrap.style.display = '';
        var pct = required > 0 ? Math.min(100, Math.round((current / required) * 100)) : 100;
        var bar = document.getElementById('monero-conf-bar');
        bar.style.width = pct + '%';
        document.getElementById('monero-conf-text').textContent = current + ' / ' + required;
    {rdelim}

    /**
     * Display the payment receipt and hide the payment details.
     *
     * Builds a formatted text receipt from the server response, shows
     * the receipt card with a copy button, sets the continue link href,
     * and optionally displays an overpayment warning if applicable.
     *
     * @param {Object} data              - Callback response with receipt and redirect_url
     * @param {Object} data.receipt      - Receipt details (order_reference, subaddress, amounts, timestamp)
     * @param {string} data.redirect_url - URL for the PrestaShop order confirmation page
     */
    function showReceipt(data) {ldelim}
        // Hide payment details
        var details = document.getElementById('monero-payment-details');
        if (details) details.style.display = 'none';

        // Build receipt text
        var r = data.receipt;
        var lines = [
            '═══ MONERO PAYMENT RECEIPT ═══',
            '',
            'Order Reference : ' + r.order_reference,
            'Subaddress      : ' + r.subaddress,
            'XMR Expected    : ' + r.xmr_expected + ' XMR',
            'XMR Received    : ' + r.xmr_received + ' XMR',
            'Timestamp       : ' + r.timestamp,
            '',
            '═══════════════════════════════',
            '',
            'SAVE THIS RECEIPT. It is the only',
            'record linking your order to payment.',
        ];

        document.getElementById('monero-receipt-text').textContent = lines.join('\n');
        document.getElementById('monero-continue-btn').href = data.redirect_url;

        // Overpayment notice
        if (r.overpaid) {ldelim}
            var notice = document.getElementById('monero-overpayment-notice');
            notice.style.display = '';
            notice.textContent = 'Overpayment detected: you sent ' + r.overpayment_xmr +
                ' XMR more than required. Contact the store with this receipt for a refund.';
        {rdelim}

        document.getElementById('monero-receipt-box').style.display = '';
    {rdelim}

    // ── Polling ──

    /**
     * Poll the callback endpoint for payment status.
     *
     * Sends the HMAC token via POST to the callback controller,
     * parses the JSON response, and updates the UI accordingly:
     *   - 'pending': shows info alert
     *   - 'confirming': shows warning alert with progress bar
     *   - 'paid': stops polling, shows success alert and receipt
     *   - 'completed': stops polling (order already exists)
     *   - 'error': shows danger alert
     *
     * Silently continues polling on network errors.
     */
    function poll() {ldelim}
        if (stopped) return;

        fetch(CALLBACK_URL, {ldelim}
            method: 'POST',
            headers: {ldelim} 'Content-Type': 'application/x-www-form-urlencoded' {rdelim},
            body: 'token=' + encodeURIComponent(TOKEN),
        {rdelim})
        .then(function(resp) {ldelim} return resp.json(); {rdelim})
        .then(function(data) {ldelim}
            if (stopped) return;

            switch (data.status) {ldelim}
                case 'pending':
                    setStatus('info', data.message);
                    break;

                case 'confirming':
                    setStatus('warning', data.message);
                    showConfirmations(data.confirmations, data.required);
                    break;

                case 'paid':
                    stopped = true;
                    if (pollTimer) clearInterval(pollTimer);
                    setStatus('success', data.message);
                    showReceipt(data);
                    return;

                case 'completed':
                    stopped = true;
                    if (pollTimer) clearInterval(pollTimer);
                    setStatus('success', 'Order already placed.');
                    return;

                case 'error':
                    setStatus('danger', data.message);
                    break;
            {rdelim}
        {rdelim})
        .catch(function() {ldelim}
            // Network error — keep polling silently
        {rdelim});
    {rdelim}

    // Start polling immediately, then every POLL_MS
    poll();
    pollTimer = setInterval(poll, POLL_MS);

{rdelim})();
</script>
{/if}
{/block}
