import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The current course (the starter kit's "team"); instructor pages always have one.
 */
export function useCourse() {
    const page = usePage();
    const course = computed(() => page.props.currentTeam!);

    return { course, slug: computed(() => course.value.slug) };
}
