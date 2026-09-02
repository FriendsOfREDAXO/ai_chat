<?php

require __DIR__ . '/settings.shared.php';

$form = rex_config_form::factory('ai_chat');

$form->addRawField('<p class="help-block">' . $rawMsg('config_section_provider_hint') . '</p>');

$field = $form->addSelectField('provider');
$field->setLabel($addon->i18n('config_provider'));
$field->setAttribute('class', 'selectpicker');
$select = $field->getSelect();
$select->addOption('Google Gemini', 'gemini');
$select->addOption('Cloudflare Workers AI', 'cloudflare');
$select->addOption('OpenWebUI / OpenAI Compatible', 'openai');
$aiPlatformAvailable = rex_addon::get('ai_platform')->isAvailable() && class_exists(\FriendsOfRedaxo\AiPlatform\Service::class);
if ($aiPlatformAvailable) {
    $select->addOption('ai_platform-Addon (gemeinsame KI-Provider-Verwaltung)', 'ai_platform');
}
$field->setAttribute('id', 'klxm-provider-select');

// Gemini
$form->addRawField('<div id="gemini-settings" class="klxm-provider-settings">');
$field = $form->addTextField('gemini_api_key');
$field->setLabel($addon->i18n('config_gemini_api_key'));
$field->setNotice($addon->i18n('config_gemini_api_key_notice'));
$field->setAttribute('id', 'klxm-gemini-api-key');
$form->addRawField('</div>');

// OpenAI
$form->addRawField('<div id="openai-settings" class="klxm-provider-settings" style="display:none;">');
$field = $form->addTextField('openai_base_url');
$field->setLabel($addon->i18n('config_openai_base_url'));
$field->setNotice($addon->i18n('config_openai_base_url_notice'));
$field->setAttribute('id', 'klxm-openai-base-url');

$field = $form->addTextField('openai_api_key');
$field->setLabel($addon->i18n('config_openai_api_key'));
$field->setNotice($addon->i18n('config_openai_api_key_notice'));
$field->setAttribute('id', 'klxm-openai-api-key');

$field = $form->addTextField('openai_model');
$field->setLabel($addon->i18n('config_openai_model'));
$field->setNotice($addon->i18n('config_openai_model_notice'));
$field->setAttribute('id', 'klxm-openai-model');

$field = $form->addTextField('openai_embedding_model');
$field->setLabel($addon->i18n('config_openai_embedding_model'));
if ($isConfigUnset($field->getValue())) {
    $field->setValue('all-MiniLM-L6-v2');
}
$field->setNotice($addon->i18n('config_openai_embedding_model_notice'));
$field->setAttribute('id', 'klxm-openai-embedding-model');
$form->addRawField('</div>');

// Cloudflare
$form->addRawField('<div id="cloudflare-settings" class="klxm-provider-settings" style="display:none;">');
$field = $form->addTextField('cloudflare_account_id');
$field->setLabel($addon->i18n('config_cloudflare_account_id'));
$field->setAttribute('id', 'klxm-cloudflare-account-id');

$field = $form->addTextField('cloudflare_api_token');
$field->setLabel($addon->i18n('config_cloudflare_api_token'));
$field->setNotice($addon->i18n('config_cloudflare_notice'));
$field->setAttribute('id', 'klxm-cloudflare-api-token');

$field = $form->addSelectField('cloudflare_model');
$field->setLabel($addon->i18n('config_cloudflare_model'));
$field->setNotice($addon->i18n('config_cloudflare_model_notice'));
$field->setAttribute('id', 'klxm-cloudflare-model-select');
$field->setAttribute('class', 'selectpicker');
$select = $field->getSelect();
$select->addOption('@cf/meta/llama-3.1-8b-instruct', '@cf/meta/llama-3.1-8b-instruct');
$select->addOption('@cf/meta/llama-3.1-70b-instruct', '@cf/meta/llama-3.1-70b-instruct');
$select->addOption('@cf/meta/llama-3.1-8b-instruct-fast', '@cf/meta/llama-3.1-8b-instruct-fast');
if ($isConfigUnset($field->getValue())) {
    $field->setValue('@cf/meta/llama-3.1-8b-instruct');
}

$form->addRawField('<div style="margin-left: 170px; margin-bottom: 15px;">');
$form->addRawField('<button type="button" class="btn btn-default" id="load-cloudflare-models-btn"><i class="rex-icon fa-refresh"></i> ' . $addon->i18n('config_cloudflare_load_models') . '</button>');
$form->addRawField('<span id="cloudflare-models-result" style="margin-left: 10px;"></span>');
$form->addRawField('</div>');
$form->addRawField('</div>');

// ai_platform: eigene Provider-Zugangsdaten überflüssig, nutzt stattdessen ein
// dort bereits konfiguriertes Profil für Text bzw. Embedding.
if ($aiPlatformAvailable) {
    $form->addRawField('<div id="ai_platform-settings" class="klxm-provider-settings" style="display:none;">');
    $form->addRawField('<p class="help-block">' . $addon->i18n('config_ai_platform_hint') . '</p>');

    $aiPlatformService = \FriendsOfRedaxo\AiPlatform\Service::getInstance();

    $field = $form->addSelectField('ai_platform_text_profile_id');
    $field->setLabel($addon->i18n('config_ai_platform_text_profile_id'));
    $field->setAttribute('id', 'klxm-ai-platform-text-profile');
    $field->setAttribute('class', 'selectpicker');
    $select = $field->getSelect();
    $select->addOption($addon->i18n('config_ai_platform_profile_none'), '');
    foreach ($aiPlatformService->getProfiles('text') as $profile) {
        $select->addOption((string) $profile['name'], (string) $profile['id']);
    }

    $field = $form->addSelectField('ai_platform_embedding_profile_id');
    $field->setLabel($addon->i18n('config_ai_platform_embedding_profile_id'));
    $field->setAttribute('id', 'klxm-ai-platform-embedding-profile');
    $field->setAttribute('class', 'selectpicker');
    $select = $field->getSelect();
    $select->addOption($addon->i18n('config_ai_platform_profile_none'), '');
    foreach ($aiPlatformService->getProfiles('embedding') as $profile) {
        $select->addOption((string) $profile['name'], (string) $profile['id']);
    }

    $form->addRawField('<p class="help-block"><a href="' . rex_url::backendPage('ai_platform/profiles') . '">ai_platform-Profile verwalten</a></p>');
    $form->addRawField('</div>');
}

// Test Connection
$form->addRawField('<div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">');
$form->addRawField('<button type="button" class="btn btn-info" id="test-klxm-connection"><i class="rex-icon fa-plug"></i> Verbindung testen</button>');
$form->addRawField('<span id="test-connection-result" style="margin-left: 10px; font-weight: bold;"></span>');
$form->addRawField('</div>');

$form->addRawField($tooltipInitScript);

$form->addRawField('
<script>
(function() {
    function initKlxmProviderSettings() {
        var providerSelect = document.getElementById("klxm-provider-select");

        function updateVisibility() {
            if (!providerSelect) return;
            var value = providerSelect.value;
            document.querySelectorAll(".klxm-provider-settings").forEach(function(el) {
                el.style.display = "none";
            });

            var target = document.getElementById(value + "-settings");
            if (target) {
                target.style.display = "block";
            }
        }

        if (providerSelect) {
            providerSelect.addEventListener("change", updateVisibility);
            updateVisibility();
        }

        // Cloudflare model loader
        var loadCfModelsBtn = document.getElementById("load-cloudflare-models-btn");
        var cfModelSelect = document.getElementById("klxm-cloudflare-model-select");
        var cfModelsResult = document.getElementById("cloudflare-models-result");

        function loadCloudflareModels() {
            if (!loadCfModelsBtn || !cfModelSelect || !cfModelsResult) {
                return;
            }

            cfModelsResult.innerHTML = "<i class=\'rex-icon fa-spinner fa-spin\'></i> Lade Modelle...";
            loadCfModelsBtn.disabled = true;

            fetch("index.php?rex-api-call=ai_chat_cloudflare_models")
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    loadCfModelsBtn.disabled = false;

                    if (!data.success) {
                        cfModelsResult.innerHTML = "<span class=\'text-danger\'><i class=\'rex-icon fa-exclamation-triangle\'></i> " + (data.message || "Fehler beim Laden der Modelle") + "</span>";
                        return;
                    }

                    if (!Array.isArray(data.models) || data.models.length === 0) {
                        cfModelsResult.innerHTML = "<span class=\'text-warning\'><i class=\'rex-icon fa-info-circle\'></i> Keine Modelle gefunden.</span>";
                        return;
                    }

                    var currentValue = cfModelSelect.value;
                    var selected = data.selected || currentValue;

                    cfModelSelect.innerHTML = "";
                    data.models.forEach(function(model) {
                        var option = document.createElement("option");
                        option.value = model;
                        option.textContent = model;
                        if (model === selected) {
                            option.selected = true;
                        }
                        cfModelSelect.appendChild(option);
                    });

                    if (typeof jQuery !== "undefined" && jQuery(cfModelSelect).selectpicker) {
                        jQuery(cfModelSelect).selectpicker("refresh");
                    }

                    cfModelsResult.innerHTML = "<span class=\'text-success\'><i class=\'rex-icon fa-check\'></i> " + data.models.length + " Modelle geladen.</span>";
                })
                .catch(function(error) {
                    loadCfModelsBtn.disabled = false;
                    cfModelsResult.innerHTML = "<span class=\'text-danger\'><i class=\'rex-icon fa-exclamation-triangle\'></i> " + error + "</span>";
                });
        }

        if (loadCfModelsBtn) {
            loadCfModelsBtn.addEventListener("click", loadCloudflareModels);
        }

        // Test Connection - testet bewusst den aktuellen (ggf. ungespeicherten) Formularstand,
        // damit ein Key vor dem Speichern geprüft werden kann.
        var testBtn = document.getElementById("test-klxm-connection");
        if (testBtn) {
            function fieldValue(id) {
                var el = document.getElementById(id);
                return el ? el.value : "";
            }

            testBtn.addEventListener("click", function() {
                var resultSpan = document.getElementById("test-connection-result");
                resultSpan.innerHTML = "<i class=\'rex-icon fa-spinner fa-spin\'></i> Teste Verbindung...";
                resultSpan.className = "";
                testBtn.disabled = true;

                var params = new URLSearchParams();
                params.set("provider", providerSelect ? providerSelect.value : "");
                params.set("gemini_api_key", fieldValue("klxm-gemini-api-key"));
                params.set("openai_base_url", fieldValue("klxm-openai-base-url"));
                params.set("openai_api_key", fieldValue("klxm-openai-api-key"));
                params.set("openai_model", fieldValue("klxm-openai-model"));
                params.set("openai_embedding_model", fieldValue("klxm-openai-embedding-model"));
                params.set("cloudflare_account_id", fieldValue("klxm-cloudflare-account-id"));
                params.set("cloudflare_api_token", fieldValue("klxm-cloudflare-api-token"));
                params.set("cloudflare_model", fieldValue("klxm-cloudflare-model-select"));
                params.set("ai_platform_text_profile_id", fieldValue("klxm-ai-platform-text-profile"));
                params.set("ai_platform_embedding_profile_id", fieldValue("klxm-ai-platform-embedding-profile"));

                fetch("index.php?rex-api-call=ai_chat_test", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: params.toString()
                })
                .then(response => response.json())
                .then(data => {
                    testBtn.disabled = false;
                    if (data.success) {
                        resultSpan.innerHTML = "<span class=\'text-success\'><i class=\'rex-icon fa-check\'></i> " + data.message + "</span>";
                    } else {
                        resultSpan.innerHTML = "<span class=\'text-danger\'><i class=\'rex-icon fa-exclamation-triangle\'></i> " + data.message + "</span>";
                    }
                })
                .catch(error => {
                    testBtn.disabled = false;
                    resultSpan.innerHTML = "<span class=\'text-danger\'><i class=\'rex-icon fa-exclamation-triangle\'></i> Fehler: " + error + "</span>";
                });
            });
        }
    }

    if (typeof jQuery !== "undefined") {
        jQuery(document).on("rex:ready", function() {
            initKlxmProviderSettings();
        });
    } else {
        document.addEventListener("DOMContentLoaded", function() {
            initKlxmProviderSettings();
        });
    }
})();
</script>
');

$renderSettingsPage($form, $renderTipsPanel($addon, 'provider'));
