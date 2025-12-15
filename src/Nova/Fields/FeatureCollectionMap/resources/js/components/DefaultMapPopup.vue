<template>
    <Teleport to="body">
        <div v-if="showPopup" class="fixed inset-0 z-[60] flex items-center justify-center px-3 md:px-0" role="dialog" aria-modal="true">
            <div class="relative z-20 bg-white dark:bg-gray-800 rounded-lg shadow-xl overflow-hidden max-w-sm w-full">
                <!-- Header -->
                <div class="bg-primary-500 dark:bg-primary-600 px-6 py-4">
                    <h3 class="text-lg font-semibold text-white">{{ popupTitle }}</h3>
                </div>

                <!-- Footer with buttons -->
                <div class="px-6 py-4 flex justify-end items-center gap-3">
                    <button type="button" @click="closePopup"
                        class="shadow relative bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 cursor-pointer rounded text-sm font-bold focus:outline-none focus:ring ring-gray-200 dark:ring-gray-600 inline-flex items-center justify-center h-9 px-3">
                        Chiudi
                    </button>
                    <a v-if="currentFeatureId" :href="resourceLink" target="_blank"
                        class="shadow relative bg-primary-500 hover:bg-primary-400 text-white dark:text-gray-900 cursor-pointer rounded text-sm font-bold focus:outline-none focus:ring ring-primary-200 dark:ring-gray-600 inline-flex items-center justify-center h-9 px-3 no-underline">
                        Vai alla risorsa
                    </a>
                </div>
            </div>
        </div>
        <!-- Backdrop -->
        <div v-if="showPopup" class="fixed inset-0 z-[55] bg-gray-500/75 dark:bg-gray-900/75" @click="closePopup"></div>
    </Teleport>
</template>

<script>
import { ref, computed, onMounted, onUnmounted } from 'vue';

export default {
    name: 'DefaultMapPopup',

    setup() {
        const showPopup = ref(false);
        const popupTitle = ref('');
        const currentFeatureId = ref(null);
        const resourceName = ref('');

        const resourceLink = computed(() => {
            if (!currentFeatureId.value || !resourceName.value) return '#';
            return `/resources/${resourceName.value}/${currentFeatureId.value}`;
        });

        const handlePopupOpen = (event) => {
            console.log('DefaultMapPopup: Received popup-open event', event);
            
            currentFeatureId.value = event.id;
            popupTitle.value = event.properties?.name || event.properties?.tooltip || `#${event.id}`;
            resourceName.value = event.resourceName || 'poles';
            
            showPopup.value = true;
        };

        const handlePopupClose = () => {
            closePopup();
        };

        const closePopup = () => {
            showPopup.value = false;
            currentFeatureId.value = null;
            popupTitle.value = '';
        };

        const handleKeydown = (event) => {
            if (event.key === 'Escape' && showPopup.value) {
                closePopup();
            }
        };

        onMounted(() => {
            console.log('DefaultMapPopup: Component mounted');
            Nova.$on('feature-collection-map:popup-open', handlePopupOpen);
            Nova.$on('feature-collection-map:popup-close', handlePopupClose);
            document.addEventListener('keydown', handleKeydown);
        });

        onUnmounted(() => {
            Nova.$off('feature-collection-map:popup-open', handlePopupOpen);
            Nova.$off('feature-collection-map:popup-close', handlePopupClose);
            document.removeEventListener('keydown', handleKeydown);
        });

        return {
            showPopup,
            popupTitle,
            currentFeatureId,
            resourceLink,
            closePopup
        };
    }
};
</script>


