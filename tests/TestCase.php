<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
    }

    private function createTestSchema(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || DB::connection()->getDatabaseName() !== 'barangay_reporting_test') {
            throw new RuntimeException('Automated tests must use the dedicated barangay_reporting_test MySQL database.');
        }

        foreach ([
            'ATTACHMENT',
            'NOTIFICATION',
            'STATUS_LOG',
            'ISSUE_REPORT',
            'LOCATION',
            'TEAM',
            'CATEGORY',
            'USERS',
            'REGISTRATION_CAPACITY_LOCK',
        ] as $tableName) {
            Schema::dropIfExists($tableName);
        }

        Schema::create('USERS', function (Blueprint $table): void {
            $table->increments('user_id')->unsigned();
            $table->string('username', 50)->unique();
            $table->string('full_name', 100);
            $table->string('email', 120)->unique();
            $table->string('password_hash');
            $table->enum('role', ['resident', 'admin'])->default('resident');
            $table->enum('account_status', ['pending', 'approved', 'rejected'])->default('approved');
            $table->string('contact_number', 30)->nullable();
            $table->dateTime('created_at')->useCurrent();
        });

        Schema::create('CATEGORY', function (Blueprint $table): void {
            $table->increments('category_id')->unsigned();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('severity_weight')->default(1);
        });

        Schema::create('TEAM', function (Blueprint $table): void {
            $table->increments('team_id')->unsigned();
            $table->string('name', 100)->unique();
        });

        Schema::create('LOCATION', function (Blueprint $table): void {
            $table->increments('location_id')->unsigned();
            $table->string('name', 150);
            $table->string('area_name', 150);
            $table->unique(['name', 'area_name']);
        });

        Schema::create('ISSUE_REPORT', function (Blueprint $table): void {
            $table->increments('report_id')->unsigned();
            $table->string('tracking_code', 23)->nullable()->unique();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('category_id');
            $table->unsignedInteger('team_id')->nullable();
            $table->text('description');
            $table->unsignedInteger('location_id');
            $table->string('landmark', 150)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->enum('status', ['Submitted', 'Validated', 'Dispatched', 'Resolved', 'Rejected'])->default('Submitted');
            $table->dateTime('date_submitted')->useCurrent();
            $table->dateTime('date_resolved')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->unsignedInteger('archived_by')->nullable();
            $table->foreign('user_id')->references('user_id')->on('USERS');
            $table->foreign('category_id')->references('category_id')->on('CATEGORY');
            $table->foreign('team_id')->references('team_id')->on('TEAM');
            $table->foreign('location_id')->references('location_id')->on('LOCATION');
            $table->foreign('archived_by')->references('user_id')->on('USERS');
        });

        Schema::create('STATUS_LOG', function (Blueprint $table): void {
            $table->increments('log_id')->unsigned();
            $table->unsignedInteger('report_id');
            $table->unsignedInteger('changed_by')->nullable();
            $table->enum('old_status', ['Submitted', 'Validated', 'Dispatched', 'Resolved', 'Rejected'])->nullable();
            $table->enum('new_status', ['Submitted', 'Validated', 'Dispatched', 'Resolved', 'Rejected']);
            $table->string('remarks', 1000)->nullable();
            $table->dateTime('changed_at')->useCurrent();
            $table->foreign('report_id')->references('report_id')->on('ISSUE_REPORT')->cascadeOnDelete();
            $table->foreign('changed_by')->references('user_id')->on('USERS')->nullOnDelete();
        });

        Schema::create('ATTACHMENT', function (Blueprint $table): void {
            $table->increments('attachment_id')->unsigned();
            $table->unsignedInteger('report_id');
            $table->string('file_path', 255)->nullable();
            $table->string('mime_type', 50)->nullable();
            $table->binary('file_data')->nullable();
            $table->dateTime('uploaded_at')->useCurrent();
            $table->foreign('report_id')->references('report_id')->on('ISSUE_REPORT')->cascadeOnDelete();
        });

        Schema::create('NOTIFICATION', function (Blueprint $table): void {
            $table->increments('notif_id')->unsigned();
            $table->unsignedInteger('report_id');
            $table->unsignedInteger('user_id');
            $table->string('message', 255);
            $table->enum('type', ['submitted', 'assigned', 'status_update', 'resolved']);
            $table->dateTime('timestamp')->useCurrent();
            $table->boolean('is_read')->default(false);
            $table->foreign('report_id')->references('report_id')->on('ISSUE_REPORT')->cascadeOnDelete();
            $table->foreign('user_id')->references('user_id')->on('USERS')->cascadeOnDelete();
        });

        Schema::create('REGISTRATION_CAPACITY_LOCK', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });

        DB::table('REGISTRATION_CAPACITY_LOCK')->insert(['id' => 1]);
    }
}
