<template>
    <DefaultField :field="field" :errors="errors" :show-help-text="showHelpText">
        <template #field>
            <input
                v-model="bboxText"
                type="text"
                class="form-control form-input form-input-bordered w-full"
                placeholder="[9.9456,43.9116,11.3524,45.0186]"
                @input="$emit('field-changed')"
            />
        </template>
    </DefaultField>
</template>

<script>
import { FormField, HandlesValidationErrors } from 'laravel-nova';

export default {
    name: 'FormBboxField',

    mixins: [FormField, HandlesValidationErrors],

    props: ['resourceName', 'resourceId'],

    data() {
        return {
            bboxText: this.field.value || '',
        };
    },

    methods: {
        fill(formData) {
            if (this.bboxText !== null && this.bboxText !== '') {
                formData.append(this.field.attribute, this.bboxText);
            }
        },
    },
};
</script>
