<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117014607 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE survey CHANGE display_mode display_mode VARCHAR(16) DEFAULT NULL, CHANGE show_duration show_duration TINYINT DEFAULT NULL');
        $this->addSql('ALTER TABLE survey_section ADD display_condition JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE survey CHANGE display_mode display_mode VARCHAR(16) DEFAULT \'LIST\' NOT NULL, CHANGE show_duration show_duration TINYINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE survey_section DROP display_condition');
    }
}
