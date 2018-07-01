<template>
    <input :type="type" :value="value" ref="input" :placeholder="getPlaceholder" :title="getTitle" @input="updateValue" />
</template>

<script>
    import Localisation from '@js/Classes/Localisation';

    export default {
        props: {
            value      : {
                type     : String,
                'default': null
            },
            variables: {
                type     : Object,
                'default': () => { return {}; }
            },
            placeholder     : {
                type     : String,
                'default': null
            },
            title      : {
                type     : String,
                'default': null
            },
            type      : {
                type     : String,
                'default': 'text'
            }
        },

        computed: {
            getPlaceholder() {
                if(this.placeholder === null) return null;

                return Localisation.translate(this.placeholder, this.variables);
            },
            getTitle() {
                if(this.title === null) return null;

                return Localisation.translate(this.title, this.variables);
            }
        },

        methods: {
            updateValue() {
                this.$emit('input', this.$refs.input.value)
            }
        }
    };
</script>

<style scoped>

</style>