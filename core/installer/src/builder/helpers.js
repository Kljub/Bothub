// Shared builder utilities — resolveVariables, buildEmbeds, buildMessageComponents

const {
    ButtonBuilder,
    ButtonStyle,
    StringSelectMenuBuilder,
    StringSelectMenuOptionBuilder,
    ActionRowBuilder,
    EmbedBuilder,
} = require('discord.js');

const BUTTON_STYLE_MAP = {
    primary:   ButtonStyle.Primary,
    secondary: ButtonStyle.Secondary,
    success:   ButtonStyle.Success,
    danger:    ButtonStyle.Danger,
    link:      ButtonStyle.Link,
};

function resolveVariables(text, interaction, optionValues, localVars, globalVars) {
    if (typeof text !== 'string') return String(text || '');

    const member      = interaction.member || null;
    const guildMember = member && typeof member.joinedAt !== 'undefined' ? member : null;
    const presence    = guildMember ? (guildMember.presence || null) : null;

    return text
        .replace(/\{user\}/gi, interaction.user.tag)
        .replace(/\{user\.name\}/gi, interaction.user.username)
        .replace(/\{user\.id\}/gi, interaction.user.id)
        .replace(/\{user\.tag\}/gi, interaction.user.tag)
        .replace(/\{user\.discriminator\}/gi, interaction.user.discriminator || '0')
        .replace(/\{user\.icon\}/gi, interaction.user.displayAvatarURL({ size: 256 }))
        .replace(/\{usericon\}/gi, interaction.user.displayAvatarURL({ size: 256 }))
        .replace(/\{user\.display_name\}/gi, guildMember ? (guildMember.displayName || interaction.user.username) : interaction.user.username)
        .replace(/\{user\.created_at\}/gi, interaction.user.createdAt ? interaction.user.createdAt.toLocaleDateString('de-DE') : '')
        .replace(/\{user\.joined_at\}/gi, guildMember && guildMember.joinedAt ? guildMember.joinedAt.toLocaleDateString('de-DE') : '')
        .replace(/\{user\.status\}/gi, presence ? (presence.status || 'offline') : 'offline')
        .replace(/\{user\.is_bot\}/gi, interaction.user.bot ? 'true' : 'false')
        .replace(/\{user\.nickname\}/gi, guildMember ? (guildMember.nickname || interaction.user.username) : interaction.user.username)
        .replace(/\{server\}/gi, interaction.guild ? interaction.guild.name : '')
        .replace(/\{server\.id\}/gi, interaction.guild ? interaction.guild.id : '')
        .replace(/\{channel\}/gi, interaction.channel ? interaction.channel.name : '')
        .replace(/\{channel\.id\}/gi, interaction.channel ? interaction.channel.id : '')
        .replace(/\{option\.([a-z0-9_-]+)\}/gi, (_, optName) => {
            if (optionValues.has(optName)) return String(optionValues.get(optName) ?? '');
            return '';
        })
        .replace(/\{local\.([a-z0-9_-]+)\}/gi, (_, key) => {
            if (localVars && localVars.has(key)) return String(localVars.get(key) ?? '');
            return '';
        })
        .replace(/\{global\.([a-z0-9_-]+)\}/gi, (_, key) => {
            if (globalVars && globalVars.has(key)) return String(globalVars.get(key) ?? '');
            return '';
        })
        .replace(/\{([a-z0-9_-]+)\.Input\.(\d+)\.InputLabel\}/g, (_, formKey, num) => {
            const k = `${formKey}.Input.${num}.InputLabel`;
            return localVars && localVars.has(k) ? String(localVars.get(k) ?? '') : '';
        });
}

function buildMessageComponents(msgNodeId, edges, nodes, botId, slashName) {
    const rows = [];

    const buttonEdges = edges.filter((e) => e.from_node_id === msgNodeId && e.from_port === 'button');
    if (buttonEdges.length > 0) {
        const buttonRow = new ActionRowBuilder();
        for (const edge of buttonEdges.slice(0, 5)) {
            const btnNode = nodes.find((n) => n.id === edge.to_node_id);
            if (!btnNode) continue;
            const cfg    = btnNode.config || {};
            const style  = cfg.style || 'primary';
            const label  = String(cfg.label || 'Button').slice(0, 80);
            const customId = String(cfg.custom_id || '').trim() || `bh:btn:${botId}:${slashName}:${btnNode.id}`;
            const btn = new ButtonBuilder().setLabel(label);
            if (style === 'link') {
                btn.setStyle(ButtonStyle.Link).setURL(customId);
            } else {
                btn.setStyle(BUTTON_STYLE_MAP[style] || ButtonStyle.Primary).setCustomId(customId.slice(0, 100));
            }
            if (cfg.emoji) {
                try { btn.setEmoji(String(cfg.emoji).trim()); } catch (_) {}
            }
            buttonRow.addComponents(btn);
        }
        if (buttonRow.components.length > 0) rows.push(buttonRow);
    }

    const menuEdge = edges.find((e) => e.from_node_id === msgNodeId && e.from_port === 'menu');
    if (menuEdge) {
        const menuNode = nodes.find((n) => n.id === menuEdge.to_node_id);
        if (menuNode) {
            const cfg      = menuNode.config || {};
            const customId = String(cfg.custom_id || '').trim() || `bh:sm:${botId}:${slashName}:${menuNode.id}`;
            const options  = Array.isArray(cfg.options) ? cfg.options : [];
            if (options.length > 0) {
                const menu = new StringSelectMenuBuilder()
                    .setCustomId(customId.slice(0, 100))
                    .setPlaceholder(String(cfg.placeholder || 'Select an option...').slice(0, 150))
                    .setMinValues(1)
                    .setMaxValues(Math.min(Number(cfg.max_values) || 1, options.length))
                    .setDisabled(cfg.disabled === 'true' || cfg.disabled === true);

                for (const opt of options.slice(0, 25)) {
                    const o = new StringSelectMenuOptionBuilder()
                        .setLabel(String(opt.label || 'Option').slice(0, 100))
                        .setValue(String(opt.value || opt.label || 'option').slice(0, 100));
                    if (opt.description) o.setDescription(String(opt.description).slice(0, 100));
                    menu.addOptions(o);
                }
                rows.push(new ActionRowBuilder().addComponents(menu));
            }
        }
    }

    return rows;
}

function buildEmbeds(embedConfigs, interaction, optionValues, localVars, globalVars) {
    if (!Array.isArray(embedConfigs) || embedConfigs.length === 0) return [];
    const embeds = [];

    for (const ec of embedConfigs.slice(0, 10)) {
        if (!ec || typeof ec !== 'object') continue;
        const embed = new EmbedBuilder();
        try {
            const colorHex = String(ec.color || '#5865F2').trim();
            if (/^#[0-9a-f]{6}$/i.test(colorHex)) embed.setColor(colorHex);

            const rv = (t) => resolveVariables(String(t || ''), interaction, optionValues, localVars, globalVars);

            const authorName = rv(ec.author_name);
            if (authorName) embed.setAuthor({ name: authorName, ...(ec.author_icon_url ? { iconURL: String(ec.author_icon_url) } : {}) });

            const title = rv(ec.title);
            if (title) { embed.setTitle(title); if (ec.url) embed.setURL(String(ec.url)); }

            const description = rv(ec.description);
            if (description) embed.setDescription(description);

            if (ec.thumbnail_url) embed.setThumbnail(String(ec.thumbnail_url));
            if (ec.image_url)     embed.setImage(String(ec.image_url));

            if (Array.isArray(ec.fields)) {
                for (const f of ec.fields.slice(0, 25)) {
                    if (!f) continue;
                    const name  = rv(f.name);
                    const value = rv(f.value);
                    if (name && value) embed.addFields({ name, value, inline: !!f.inline });
                }
            }

            const footerText = rv(ec.footer_text);
            if (footerText) embed.setFooter({ text: footerText });
            if (ec.timestamp) embed.setTimestamp();

            embeds.push(embed);
        } catch (error) {
            console.warn('[builder/helpers] Embed build error:', error instanceof Error ? error.message : String(error));
        }
    }

    return embeds;
}

module.exports = { resolveVariables, buildEmbeds, buildMessageComponents, BUTTON_STYLE_MAP };
