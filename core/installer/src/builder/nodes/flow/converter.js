const type = 'action.converter';

async function execute(ctx) {
    const { cfg, node, getNextNode, localVars, rv } = ctx;

    const operation = String(cfg.operation || 'encode_text');
    const varName   = String(cfg.var_name  || '').trim();
    const input     = rv(String(cfg.input  || '')).trim();

    if (varName !== '' && input !== '') {
        switch (operation) {
            case 'encode_text': {
                localVars.set(varName, Buffer.from(input, 'utf8').toString('base64'));
                break;
            }
            case 'decode_text': {
                try {
                    localVars.set(varName, Buffer.from(input, 'base64').toString('utf8'));
                } catch (_) {
                    localVars.set(varName, '');
                }
                break;
            }
            case 'encode_image': {
                try {
                    const res  = await fetch(input);
                    const mime = res.headers.get('content-type') || 'image/png';
                    const b64  = Buffer.from(await res.arrayBuffer()).toString('base64');
                    localVars.set(varName,               b64);
                    localVars.set(varName + '.data_uri', `data:${mime};base64,${b64}`);
                    localVars.set(varName + '.mime',     mime);
                } catch (_) {
                    localVars.set(varName,               '');
                    localVars.set(varName + '.data_uri', '');
                    localVars.set(varName + '.mime',     '');
                }
                break;
            }
            case 'decode_image': {
                try {
                    let raw          = input;
                    let detectedMime = 'image/png';
                    const m = raw.match(/^data:([^;]+);base64,(.+)$/s);
                    if (m) { detectedMime = m[1]; raw = m[2]; }
                    const filename = String(cfg.filename || 'image.png').trim() || 'image.png';
                    const buffer   = Buffer.from(raw, 'base64');
                    localVars.set(varName, { _bh_attachment: true, buffer, filename, mime: detectedMime });
                    localVars.set(varName + '.filename', filename);
                    localVars.set(varName + '.mime',     detectedMime);
                } catch (_) {
                    localVars.set(varName,               '');
                    localVars.set(varName + '.filename', '');
                    localVars.set(varName + '.mime',     '');
                }
                break;
            }
            case 'json_parse': {
                try {
                    const parsed = JSON.parse(input);
                    if (parsed && typeof parsed === 'object') {
                        for (const [k, v] of Object.entries(parsed)) {
                            localVars.set(`${varName}.${k}`, String(v ?? ''));
                        }
                    }
                    localVars.set(varName, input);
                } catch (_) {
                    localVars.set(varName, '');
                }
                break;
            }
            case 'json_stringify': {
                try {
                    localVars.set(varName, JSON.stringify(JSON.parse(input)));
                } catch (_) {
                    localVars.set(varName, input);
                }
                break;
            }
            case 'uppercase':    localVars.set(varName, input.toUpperCase()); break;
            case 'lowercase':    localVars.set(varName, input.toLowerCase()); break;
            case 'trim':         localVars.set(varName, input.trim()); break;
            case 'length':       localVars.set(varName, String(input.length)); break;
        }
    }

    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
