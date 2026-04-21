// PFAD: /core/installer/src/flow/nodes/action_flow_wait.js

module.exports = {
    type: 'action.flow.wait',

    async execute(context) {
        const props = context.node.properties || {};
        let duration = parseInt(props.duration, 10);

        // Validierung analog zum PHP-Backend (min 1, max 600)
        if (isNaN(duration) || duration < 1) {
            duration = 1;
        }
        if (duration > 600) {
            duration = 600;
        }

        // Warten (in Millisekunden umrechnen)
        await new Promise(resolve => setTimeout(resolve, duration * 1000));

        // Zum nächsten Port ('next') im Flow weitergehen
        return 'next';
    }
};