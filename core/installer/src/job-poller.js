// PFAD: /core/installer/src/job-poller.js

const { dbQuery } = require('./db');
const { runnerName } = require('./config');

class JobPoller {
    constructor(botManager) {
        this.botManager = botManager;
        this.isRunning = false;
    }

    async claimNextJob() {
        await dbQuery(
            `
            UPDATE runner_jobs
            SET status = 'running',
                last_error = NULL,
                started_at = NOW(),
                updated_at = NOW()
            WHERE id = (
                SELECT id FROM (
                    SELECT id
                    FROM runner_jobs
                    WHERE status = 'queued'
                      AND available_at <= NOW()
                      AND job_type IN ('bot_start', 'bot_stop', 'bot_restart', 'bot_sync_config', 'bot_deploy')
                    ORDER BY priority DESC, id ASC
                    LIMIT 1
                ) picked
            )
            AND status = 'queued'
            `,
            []
        );

        const rows = await dbQuery(
            `
            SELECT
                id,
                job_uid,
                bot_id,
                job_type,
                payload_json,
                status
            FROM runner_jobs
            WHERE status = 'running'
              AND started_at IS NOT NULL
              AND finished_at IS NULL
            ORDER BY started_at ASC, id ASC
            LIMIT 1
            `,
            []
        );

        if (!Array.isArray(rows) || rows.length === 0) {
            return null;
        }

        return rows[0];
    }

    async loadBotById(botId) {
        const rows = await dbQuery(
            `
            SELECT
                id,
                display_name,
                discord_app_id,
                discord_bot_user_id,
                bot_token_encrypted,
                desired_state,
                runtime_status,
                is_active
            FROM bot_instances
            WHERE id = ?
            LIMIT 1
            `,
            [Number(botId)]
        );

        if (!Array.isArray(rows) || rows.length === 0) {
            return null;
        }

        return rows[0];
    }

    async markJobDone(jobId) {
        await dbQuery(
            `
            UPDATE runner_jobs
            SET status = 'done',
                finished_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
            `,
            [Number(jobId)]
        );
    }

    async markJobFailed(jobId, errorMessage) {
        await dbQuery(
            `
            UPDATE runner_jobs
            SET status = 'failed',
                finished_at = NOW(),
                last_error = ?,
                updated_at = NOW()
            WHERE id = ?
            `,
            [String(errorMessage || 'Unknown error'), Number(jobId)]
        );
    }

    async handleJob(job) {
        const jobId = Number(job.id || 0);
        const botId = Number(job.bot_id || 0);
        const jobType = String(job.job_type || '');

        if (jobId <= 0) {
            return;
        }

        if (botId <= 0) {
            await this.markJobFailed(jobId, 'Missing bot_id');
            return;
        }

        const botRow = await this.loadBotById(botId);

        if (!botRow) {
            await this.markJobFailed(jobId, `Bot not found: ${botId}`);
            return;
        }

        try {
            if (jobType === 'bot_start') {
                botRow.desired_state = 'running';
                await this.botManager.startBot(botRow);
            } else if (jobType === 'bot_stop') {
                await this.botManager.stopBot(botId, null);
            } else if (jobType === 'bot_restart') {
                botRow.desired_state = 'running';
                await this.botManager.restartBot(botRow);
            } else if (jobType === 'bot_sync_config' || jobType === 'bot_deploy') {
                if (String(botRow.desired_state || 'stopped') === 'running') {
                    await this.botManager.restartBot(botRow);
                } else {
                    await this.botManager.stopBot(botId, null);
                }
            } else {
                await this.markJobFailed(jobId, `Unsupported job type: ${jobType}`);
                return;
            }

            await this.markJobDone(jobId);
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            await this.markJobFailed(jobId, message);
        }
    }

    async pollOnce() {
        if (this.isRunning) {
            return;
        }

        this.isRunning = true;

        try {
            const job = await this.claimNextJob();

            if (job) {
                await this.handleJob(job);
            }

            await this.botManager.syncAllBots();
        } finally {
            this.isRunning = false;
        }
    }
}

module.exports = {
    JobPoller
};