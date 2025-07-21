    <?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration
    {
        /**
         * Run the migrations.
         *
         * @return void
         */
        public function up(): void
        {
            Schema::create('whatsapp_sessions', function (Blueprint $table) {
                // Define the primary key for the table
                $table->id();

                // Store the unique phone number of the WhatsApp user
                $table->string('phone_number')->unique();

                // JSON column to store the full conversation history (messages and AI responses)
                $table->json('conversation_history')->nullable();

                // String to track the current stage of the qualification process (e.g., 'initial', 'spin_s', 'bant_b', 'qualified')
                $table->string('current_stage')->default('initial');

                // JSON column to store the collected data from SPIN/BANT questions
                $table->json('qualification_data')->nullable();

                // Foreign key to link to the 'leads' table once a lead is created
                // ATENÇÃO: Se leads.id for INT UNSIGNED, altere para $table->unsignedInteger('lead_id')->nullable();
                $table->unsignedInteger('lead_id')->nullable(); // Alterado para unsignedInteger
                $table->foreign('lead_id')->references('id')->on('leads')->onDelete('set null');

                // Timestamps for creation and last update
                $table->timestamps();
            });
        }

        /**
         * Reverse the migrations.
         *
         * @return void
         */
        public function down(): void
        {
            // Drop the table if the migration is rolled back
            Schema::dropIfExists('whatsapp_sessions');
        }
    };
    