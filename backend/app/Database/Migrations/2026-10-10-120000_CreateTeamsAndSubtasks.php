<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTeamsAndSubtasks extends Migration
{
    public function up()
    {
        // ── teams ──────────────────────────────────────────────
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'team_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => false,
            ],
            'team_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => false,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'team_leader_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'department_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'status' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'created_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('team_code');
        $this->forge->addKey('status');
        $this->forge->addKey('team_leader_id');
        $this->forge->createTable('teams', true);

        // ── team_members ───────────────────────────────────────
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'team_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'employee_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'joined_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'status' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('team_id');
        $this->forge->addKey('employee_id');
        $this->forge->addUniqueKey(['team_id', 'employee_id'], 'uk_team_employee');
        $this->forge->createTable('team_members', true);

        // ── task_subtasks ──────────────────────────────────────
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'task_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'assigned_employee_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'start_date' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'due_date' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'priority' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'normal',
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'pending',
            ],
            'progress_percentage' => [
                'type'       => 'TINYINT',
                'unsigned'   => true,
                'default'    => 0,
            ],
            'remarks' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'completed_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'completed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('task_id');
        $this->forge->addKey('assigned_employee_id');
        $this->forge->addKey('status');
        $this->forge->createTable('task_subtasks', true);

        // ── Alter project_tasks: add team assignment columns ───
        $db = db_connect();
        $fields = $db->getFieldNames('project_tasks');

        if (!in_array('assignment_type', $fields, true)) {
            $db->query("ALTER TABLE `project_tasks` ADD COLUMN `assignment_type` VARCHAR(20) NOT NULL DEFAULT 'individual' AFTER `assigned_to`");
        }
        if (!in_array('assigned_team_id', $fields, true)) {
            $db->query("ALTER TABLE `project_tasks` ADD COLUMN `assigned_team_id` INT UNSIGNED NULL DEFAULT NULL AFTER `assignment_type`");
        }
    }

    public function down()
    {
        $this->forge->dropTable('task_subtasks', true);
        $this->forge->dropTable('team_members', true);
        $this->forge->dropTable('teams', true);

        // Remove added columns from project_tasks
        $db = db_connect();
        $fields = $db->getFieldNames('project_tasks');
        if (in_array('assignment_type', $fields, true)) {
            $db->query("ALTER TABLE `project_tasks` DROP COLUMN `assignment_type`");
        }
        if (in_array('assigned_team_id', $fields, true)) {
            $db->query("ALTER TABLE `project_tasks` DROP COLUMN `assigned_team_id`");
        }
    }
}
