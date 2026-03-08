/**
 * Neuro Link — Async Chat (Enqueue + Poll)
 * File: admin/js/nl-chat-async.js
 *
 * Replaces the direct /chat synchronous call for the Chat tab.
 * Flow: POST /run → get request_id → trigger AJAX worker → poll /status/{id}
 *
 * Attach after nl-chat.js or replace the send handler in page-chat.php.
 */
( function () {
    'use strict';

    // ── Config ───────────────────────────────────────────────────────────────
    const API_BASE    = window.nlData?.restBase  || '/wp-json/neuro-link/v1';
    const NONCE       = window.nlData?.nonce     || '';
    const AJAX_URL    = window.nlData?.ajaxUrl   || '/wp-admin/admin-ajax.php';
    const WORKER_NONCE = window.nlData?.workerNonce || '';

    const POLL_INTERVAL_MS = 1200;   // how often to check status
    const POLL_MAX_TRIES   = 50;     // give up after ~60 s
    const TERMINAL_STATES  = new Set( [ 'completed', 'dead_letter', 'cancelled' ] );

    // ── Helpers ───────────────────────────────────────────────────────────────
    function apiFetch( path, opts = {} ) {
        return fetch( API_BASE + path, {
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   NONCE,
                ...( opts.headers || {} ),
            },
            ...opts,
        } ).then( r => r.json() );
    }

    function triggerWorker() {
        const fd = new FormData();
        fd.append( 'action', 'nl_run_worker' );
        fd.append( 'nonce',  WORKER_NONCE );
        // Fire-and-forget — we don't need to wait for it.
        fetch( AJAX_URL, { method: 'POST', body: fd } ).catch( () => {} );
    }

    // ── Core: enqueue → poll ──────────────────────────────────────────────────
    /**
     * Send a message through the async queue.
     *
     * @param {string}   input        The user's message text.
     * @param {function} onQueued     Called with request_id once enqueued.
     * @param {function} onComplete   Called with the full task row on completion.
     * @param {function} onError      Called with an error string on failure.
     * @param {function} onTick       Optional — called each poll tick with status string.
     */
    function sendAsync( input, { onQueued, onComplete, onError, onTick } = {} ) {

        // 1. Enqueue.
        apiFetch( '/run', {
            method: 'POST',
            body: JSON.stringify( { input } ),
        } )
        .then( data => {
            if ( ! data?.request_id ) {
                onError?.( 'Enqueue failed: no request_id returned.' );
                return;
            }

            const requestId = data.request_id;
            onQueued?.( requestId );

            // 2. Trigger worker over AJAX so it runs now, not next cron tick.
            triggerWorker();

            // 3. Poll status.
            let tries = 0;
            const interval = setInterval( () => {
                tries++;

                if ( tries > POLL_MAX_TRIES ) {
                    clearInterval( interval );
                    onError?.( 'Timed out waiting for response.' );
                    return;
                }

                apiFetch( `/status/${ requestId }` )
                .then( task => {
                    onTick?.( task.status );

                    if ( TERMINAL_STATES.has( task.status ) ) {
                        clearInterval( interval );

                        if ( task.status === 'completed' ) {
                            const result = JSON.parse( task.result_json || '{}' );
                            onComplete?.( result );
                        } else {
                            onError?.( task.error_message || `Task ended with status: ${ task.status }` );
                        }
                    }
                } )
                .catch( err => {
                    // Don't abort on a single failed poll — just log.
                    console.warn( '[NL] Poll error:', err );
                } );

            }, POLL_INTERVAL_MS );
        } )
        .catch( err => {
            onError?.( 'Network error: ' + err.message );
        } );
    }

    // ── UI wiring ─────────────────────────────────────────────────────────────
    function init() {
        const sendBtn  = document.getElementById( 'nl-chat-send' );
        const inputEl  = document.getElementById( 'nl-chat-input' );
        const msgList  = document.getElementById( 'nl-chat-messages' );

        if ( ! sendBtn || ! inputEl || ! msgList ) return; // not on chat page

        function appendMessage( role, html ) {
            const div = document.createElement( 'div' );
            div.className = `nl-message nl-message--${ role }`;
            div.innerHTML = html;
            msgList.querySelector( '.nl-empty' )?.remove();
            msgList.appendChild( div );
            msgList.scrollTop = msgList.scrollHeight;
            return div;
        }

        function setSending( busy ) {
            sendBtn.disabled = busy;
            sendBtn.textContent = busy ? 'Sending…' : 'Send';
            inputEl.disabled = busy;
        }

        function handleSend() {
            const text = inputEl.value.trim();
            if ( ! text ) return;

            inputEl.value = '';
            setSending( true );
            appendMessage( 'user', escHtml( text ) );

            // Placeholder while waiting.
            const placeholder = appendMessage( 'assistant', '<span class="nl-typing">⋯</span>' );

            sendAsync( text, {
                onQueued: ( id ) => {
                    placeholder.dataset.requestId = id;
                },
                onTick: ( status ) => {
                    placeholder.querySelector( '.nl-typing' ).textContent =
                        status === 'running' ? '⏳ Running…' : '⋯';
                },
                onComplete: ( result ) => {
                    placeholder.innerHTML = escHtml( result.text || '(empty response)' );
                    setSending( false );
                },
                onError: ( msg ) => {
                    placeholder.innerHTML = `<span class="nl-error">⚠ ${ escHtml( msg ) }</span>`;
                    setSending( false );
                },
            } );
        }

        sendBtn.addEventListener( 'click', handleSend );
        inputEl.addEventListener( 'keydown', e => {
            if ( e.key === 'Enter' && ! e.shiftKey ) { e.preventDefault(); handleSend(); }
        } );
    }

    function escHtml( s ) {
        return String( s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )();
