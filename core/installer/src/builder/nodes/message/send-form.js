const { ModalBuilder, TextInputBuilder, TextInputStyle, ActionRowBuilder } = require('discord.js');

const type = 'action.message.send_form';

async function execute(ctx) {
    const { cfg, node, interaction, getNextNode, localVars, botId, slashName } = ctx;

    const formTitle  = String(cfg.form_title || 'Form').slice(0, 45);
    const formName   = String(cfg.form_name  || 'form').trim();
    const formFields = Array.isArray(cfg.fields) ? cfg.fields : [];
    const customId   = `bh:form:${botId}:${slashName}:${node.id}`;

    const modal = new ModalBuilder()
        .setTitle(formTitle)
        .setCustomId(customId.slice(0, 100));

    formFields.slice(0, 5).forEach((f, i) => {
        if (f.hidden === 'true' || f.hidden === true) return;
        const fieldId = `field_${i + 1}`;
        const inp = new TextInputBuilder()
            .setCustomId(fieldId)
            .setLabel(String(f.label || 'Input').slice(0, 45))
            .setStyle(f.style === 'paragraph' ? TextInputStyle.Paragraph : TextInputStyle.Short)
            .setRequired(f.required !== 'false' && f.required !== false);
        if (f.placeholder) inp.setPlaceholder(String(f.placeholder).slice(0, 100));
        if (f.min_length)  inp.setMinLength(Math.max(0, Number(f.min_length)));
        if (f.max_length)  inp.setMaxLength(Math.min(4000, Number(f.max_length)));
        if (f.default)     inp.setValue(String(f.default).slice(0, 4000));
        modal.addComponents(new ActionRowBuilder().addComponents(inp));
    });

    await interaction.showModal(modal);

    const submitted = await interaction.awaitModalSubmit({
        filter: (i) => i.customId === customId && i.user.id === interaction.user.id,
        time: 15 * 60 * 1000,
    }).catch(() => null);

    if (submitted) {
        formFields.slice(0, 5).forEach((f, i) => {
            if (f.hidden === 'true' || f.hidden === true) return;
            try {
                const val = submitted.fields.getTextInputValue(`field_${i + 1}`) ?? '';
                localVars.set(`${formName}.Input.${i + 1}.InputLabel`, String(val));
            } catch (_) {
                localVars.set(`${formName}.Input.${i + 1}.InputLabel`, '');
            }
        });
        if (!submitted.replied && !submitted.deferred) {
            await submitted.deferReply({ ephemeral: true }).catch(() => {});
        }
    }

    ctx.currentNode = getNextNode(node.id, 'next');
}

module.exports = { type, execute };
