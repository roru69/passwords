<template>
    <component :is="tag" :class="getClass" :title="getTitle" aria-hidden="true"/>
</template>

<script>
    import Localisation from '@js/Classes/Localisation';

    export default {
        props: {
            icon : {
                type: String
            },
            title: {
                type   : String,
                default: null
            },
            tag: {
                type   : String,
                default: 'i'
            }
        },

        computed: {
            getClass() {
                return `fa fa-${this.icon}`;
            },
            getTitle() {
                return this.title!== null ? Localisation.translate(this.title):null;
            }
        }
    }
</script>