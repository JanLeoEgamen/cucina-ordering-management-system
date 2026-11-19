<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The audit trail: one row per thing a person did (signed in, changed a record, opened a
     * page, was refused). Append only: the app has no code that updates or deletes a row
     * except `php artisan audit:prune`, which drops what is older than the retention window.
     *
     * Not soft deleted and no foreign keys, on purpose: a log row must outlive the person
     * and the record it is about (users and dishes are soft deleted, roles are really
     * deleted) and must never block them. So the person's name / email and the record's
     * label are COPIED into the row at the time, and stay readable when the original is gone.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Who. Null for the system (the prune command) and for a sign-in that failed
            // for an address nobody owns; the copies below say who it was at the time.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 160)->nullable();
            $table->string('user_email', 191)->nullable();

            // auth (sign in / out / failed) · action (a change or an attempt) · view (a page opened) · system.
            $table->string('event', 12);
            // The route name (`users.destroy`) or `auth.login`; "POST path" for a route without a name.
            $table->string('action', 80);
            // Ready to print: "Deleted a user", "Signed in".
            $table->string('description', 255);

            // What it was about: the page's own name (`users`, `orders`...), its id and a label
            // copied at the time ("Jane Doe", "ORD-20261119-0001") so a deleted record stays readable.
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 160)->nullable();

            $table->string('method', 10)->nullable();
            // The path only, never the query string (a OAuth `code=` or a reset token can sit in it).
            $table->string('path', 255)->nullable();
            // success · failed (validation, a rule said no) · denied (403 / 401) · error (5xx).
            $table->string('outcome', 10)->default('success');
            $table->unsignedSmallInteger('status')->nullable();
            // What the app answered ("A Super Admin account cannot be deleted."), when it said something.
            $table->string('message', 255)->nullable();

            // {"field": [before, after]} for a change to a known record.
            $table->json('changes')->nullable();
            // What was sent, with passwords / tokens hidden and uploads reduced to a name.
            $table->json('input')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // One person's timeline, newest first.
            $table->index(['user_id', 'id'], 'audit_logs_user_index');
            // "Everything that happened to this record".
            $table->index(['subject_type', 'subject_id'], 'audit_logs_subject_index');
            // The date filter and the prune command.
            $table->index('created_at', 'audit_logs_created_index');
            // The event / outcome filters (the page is newest first, so they end on the id).
            $table->index(['event', 'id'], 'audit_logs_event_index');
            $table->index(['outcome', 'id'], 'audit_logs_outcome_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
