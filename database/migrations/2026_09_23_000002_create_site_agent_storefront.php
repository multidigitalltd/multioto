<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selling סוכן האתר without us: a page to buy it on, and a price for a second
 * manager's number.
 *
 * Until now the product could only be sold by a team member opening three rows
 * by hand on the activation screen. That works for a sale somebody already made
 * on the phone; it is not a product anybody can buy. What was missing is
 * mechanical: somewhere for a stranger's purchase to live between "they left for
 * the payment page" and "the money arrived", which is exactly what plugin_orders
 * is for the plugin store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // What a SECOND manager's number costs on this plan, per cycle.
            //
            // Null means the plan does not sell extra numbers at all, which is
            // not the same as selling them for nothing: a plan with no price
            // for them must not put a "הוסיפו מספר — ₪0" button on a customer's
            // screen. Zero, set deliberately, is "included".
            $table->unsignedBigInteger('extra_number_price_agorot')->nullable()->after('price_agorot');

            // May a stranger buy this plan on the public page?
            //
            // Off by default, and that default is the point: the plans in the
            // table today include prices agreed with one customer. Making every
            // active plan sellable would have published them, and let anyone
            // buy at the lowest one.
            $table->boolean('is_public')->default(false)->index()->after('active');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // Extra manager numbers PAID FOR on this subscription.
            //
            // Counted rather than derived from the bound numbers, because those
            // move for reasons that are not sales: the team binds a number to
            // test, a manager is revoked for a week, a customer's phone changes.
            // A price that follows those would bill a customer for an afternoon
            // and shrink their invoice while their manager is away.
            $table->unsignedSmallInteger('agent_extra_numbers')->default(0)->after('price_agorot_override');
        });

        Schema::create('site_agent_orders', function (Blueprint $table) {
            $table->id();

            // Addressed by this, never by id: the pages that show an order are
            // public, and a row id is a number anybody can count to.
            $table->string('reference', 40)->unique();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();

            // What the buyer typed. Kept beside the customer record rather than
            // folded into it: a returning customer's name on file is the one we
            // invoice, and this is the one to answer if the purchase goes wrong.
            $table->string('buyer_name', 120);
            $table->string('buyer_email', 150);

            // The number that will drive the site, normalised to the same
            // international form SiteAgentSubscriber stores.
            $table->string('manager_phone', 32);
            $table->string('manager_name', 120)->nullable();

            // The site it is bought for. The host as the buyer typed it, and
            // the row once we have created it.
            $table->string('domain', 190);
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('total_agorot');

            // Who installs the plugin. Asked at checkout because it decides what
            // the buyer sees on the very next screen — codes to paste, or a form
            // to hand us access — and asking afterwards means a buyer staring at
            // instructions they never wanted.
            $table->string('install_mode', 16)->default('self');

            $table->string('status', 16)->default('pending')->index();

            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('site_installations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_agent_order_id')->nullable()->constrained()->nullOnDelete();

            $table->string('domain', 190);

            // awaiting_access → ready → installed (or failed / canceled).
            $table->string('state', 24)->default('awaiting_access')->index();

            // 'temp_login' (a link from a temporary-login plugin) or
            // 'credentials' (a username and password they created for us).
            $table->string('access_method', 16)->nullable();

            // The credential itself, encrypted at rest and never logged.
            //
            // A column holding customers' WordPress admin access is the most
            // dangerous thing in this database, so it is built to be EMPTY most
            // of the time: wiped the moment the install is done, and swept by
            // PruneSiteAccessJob once it expires. What is stored is what the
            // customer chose to give us and no more — we never ask for the
            // password they use themselves.
            $table->text('access_secret')->nullable();

            // Anything that is not a credential: "the login is under /wp-admin2",
            // "call before you start". Plain, because it is shown on the queue.
            $table->string('access_note', 500)->nullable();

            // When the customer says the access stops working. Believed, not
            // trusted: the sweep uses it, and its absence means the default.
            $table->timestamp('access_expires_at')->nullable();

            // When the secret was wiped, so the screen can say "it was here and
            // is gone" rather than "nothing was ever handed over".
            $table->timestamp('access_cleared_at')->nullable();

            $table->timestamp('installed_at')->nullable();
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_installations');
        Schema::dropIfExists('site_agent_orders');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('agent_extra_numbers');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['extra_number_price_agorot', 'is_public']);
        });
    }
};
