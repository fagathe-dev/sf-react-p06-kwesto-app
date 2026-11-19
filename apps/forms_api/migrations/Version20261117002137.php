<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117002137 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, display_name VARCHAR(100) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_88BDF3E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE question (id BINARY(16) NOT NULL, type VARCHAR(16) NOT NULL, label VARCHAR(500) NOT NULL, help_title VARCHAR(80) DEFAULT NULL, help_text LONGTEXT DEFAULT NULL, required TINYINT DEFAULT 1 NOT NULL, position INT NOT NULL, config JSON NOT NULL, display_condition JSON DEFAULT NULL, survey_id BINARY(16) NOT NULL, section_id BINARY(16) DEFAULT NULL, INDEX idx_question_survey_position (survey_id, position), FULLTEXT INDEX ft_question_search (label, help_text), INDEX IDX_B6F7494EB3FE509D (survey_id), INDEX IDX_B6F7494ED823E37A (section_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE survey (id BINARY(16) NOT NULL, title VARCHAR(150) NOT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(16) NOT NULL, display_mode VARCHAR(16) DEFAULT \'LIST\' NOT NULL, show_duration TINYINT DEFAULT 1 NOT NULL, expires_at DATETIME DEFAULT NULL, validated_at DATETIME DEFAULT NULL, activated_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, close_reason VARCHAR(24) DEFAULT NULL, reminder_sent_at DATETIME DEFAULT NULL, reminders_count INT DEFAULT 0 NOT NULL, stats_snapshot JSON DEFAULT NULL, stats_computed_at DATETIME DEFAULT NULL, archive_path VARCHAR(255) DEFAULT NULL, telemetry_anonymized_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id BINARY(16) NOT NULL, INDEX idx_survey_status_expires (status, expires_at), FULLTEXT INDEX ft_survey_search (title, description), INDEX IDX_AD5F9BFC7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE survey_answer (id BINARY(16) NOT NULL, payload JSON NOT NULL, search_text LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, survey_id BINARY(16) NOT NULL, recipient_id BINARY(16) NOT NULL, question_id BINARY(16) NOT NULL, INDEX idx_answer_question (question_id), FULLTEXT INDEX ft_answer_search (search_text), UNIQUE INDEX uniq_answer_recipient_question (recipient_id, question_id), INDEX IDX_F2D38249B3FE509D (survey_id), INDEX IDX_F2D38249E92F8F78 (recipient_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE survey_recipient (id BINARY(16) NOT NULL, email VARCHAR(254) NOT NULL, token CHAR(64) DEFAULT NULL, status VARCHAR(16) NOT NULL, sent_at DATETIME DEFAULT NULL, opened_at DATETIME DEFAULT NULL, submitted_at DATETIME DEFAULT NULL, reminder_sent_at DATETIME DEFAULT NULL, bounced_at DATETIME DEFAULT NULL, send_attempts INT DEFAULT 0 NOT NULL, last_error VARCHAR(500) DEFAULT NULL, ip_address VARCHAR(64) DEFAULT NULL, user_agent_raw LONGTEXT DEFAULT NULL, browser_name VARCHAR(40) DEFAULT NULL, browser_version VARCHAR(16) DEFAULT NULL, os_name VARCHAR(40) DEFAULT NULL, device_type VARCHAR(16) DEFAULT NULL, completion_seconds INT DEFAULT NULL, created_at DATETIME NOT NULL, draft_answers JSON DEFAULT NULL, draft_question_id VARCHAR(36) DEFAULT NULL, draft_saved_at DATETIME DEFAULT NULL, survey_id BINARY(16) NOT NULL, INDEX idx_recipient_survey_status (survey_id, status), FULLTEXT INDEX ft_recipient_search (email), UNIQUE INDEX uniq_recipient_survey_email (survey_id, email), UNIQUE INDEX uniq_recipient_token (token), INDEX IDX_CD52B964B3FE509D (survey_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE survey_section (id BINARY(16) NOT NULL, title VARCHAR(150) NOT NULL, description LONGTEXT DEFAULT NULL, position INT NOT NULL, survey_id BINARY(16) NOT NULL, INDEX idx_section_survey_position (survey_id, position), INDEX IDX_695F5954B3FE509D (survey_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE uploaded_asset (id BINARY(16) NOT NULL, original_name VARCHAR(255) NOT NULL, stored_path VARCHAR(255) NOT NULL, mime_type VARCHAR(127) NOT NULL, extension VARCHAR(16) NOT NULL, size INT NOT NULL, created_at DATETIME NOT NULL, survey_id BINARY(16) NOT NULL, question_id BINARY(16) NOT NULL, recipient_id BINARY(16) NOT NULL, answer_id BINARY(16) DEFAULT NULL, INDEX idx_asset_orphans (answer_id, created_at), INDEX IDX_AFAF5B0B3FE509D (survey_id), INDEX IDX_AFAF5B01E27F6BF (question_id), INDEX IDX_AFAF5B0E92F8F78 (recipient_id), INDEX IDX_AFAF5B0AA334807 (answer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE question ADD CONSTRAINT FK_B6F7494EB3FE509D FOREIGN KEY (survey_id) REFERENCES survey (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE question ADD CONSTRAINT FK_B6F7494ED823E37A FOREIGN KEY (section_id) REFERENCES survey_section (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE survey ADD CONSTRAINT FK_AD5F9BFC7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_answer ADD CONSTRAINT FK_F2D38249B3FE509D FOREIGN KEY (survey_id) REFERENCES survey (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_answer ADD CONSTRAINT FK_F2D38249E92F8F78 FOREIGN KEY (recipient_id) REFERENCES survey_recipient (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_answer ADD CONSTRAINT FK_F2D382491E27F6BF FOREIGN KEY (question_id) REFERENCES question (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_recipient ADD CONSTRAINT FK_CD52B964B3FE509D FOREIGN KEY (survey_id) REFERENCES survey (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_section ADD CONSTRAINT FK_695F5954B3FE509D FOREIGN KEY (survey_id) REFERENCES survey (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE uploaded_asset ADD CONSTRAINT FK_AFAF5B0B3FE509D FOREIGN KEY (survey_id) REFERENCES survey (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE uploaded_asset ADD CONSTRAINT FK_AFAF5B01E27F6BF FOREIGN KEY (question_id) REFERENCES question (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE uploaded_asset ADD CONSTRAINT FK_AFAF5B0E92F8F78 FOREIGN KEY (recipient_id) REFERENCES survey_recipient (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE uploaded_asset ADD CONSTRAINT FK_AFAF5B0AA334807 FOREIGN KEY (answer_id) REFERENCES survey_answer (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE question DROP FOREIGN KEY FK_B6F7494EB3FE509D');
        $this->addSql('ALTER TABLE question DROP FOREIGN KEY FK_B6F7494ED823E37A');
        $this->addSql('ALTER TABLE survey DROP FOREIGN KEY FK_AD5F9BFC7E3C61F9');
        $this->addSql('ALTER TABLE survey_answer DROP FOREIGN KEY FK_F2D38249B3FE509D');
        $this->addSql('ALTER TABLE survey_answer DROP FOREIGN KEY FK_F2D38249E92F8F78');
        $this->addSql('ALTER TABLE survey_answer DROP FOREIGN KEY FK_F2D382491E27F6BF');
        $this->addSql('ALTER TABLE survey_recipient DROP FOREIGN KEY FK_CD52B964B3FE509D');
        $this->addSql('ALTER TABLE survey_section DROP FOREIGN KEY FK_695F5954B3FE509D');
        $this->addSql('ALTER TABLE uploaded_asset DROP FOREIGN KEY FK_AFAF5B0B3FE509D');
        $this->addSql('ALTER TABLE uploaded_asset DROP FOREIGN KEY FK_AFAF5B01E27F6BF');
        $this->addSql('ALTER TABLE uploaded_asset DROP FOREIGN KEY FK_AFAF5B0E92F8F78');
        $this->addSql('ALTER TABLE uploaded_asset DROP FOREIGN KEY FK_AFAF5B0AA334807');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE question');
        $this->addSql('DROP TABLE survey');
        $this->addSql('DROP TABLE survey_answer');
        $this->addSql('DROP TABLE survey_recipient');
        $this->addSql('DROP TABLE survey_section');
        $this->addSql('DROP TABLE uploaded_asset');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
