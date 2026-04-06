import { defineCollection, z } from 'astro:content';

const updates = defineCollection({
  type: 'content',
  schema: z.object({
    title: z.string(),
    date: z.date(),
    author: z.string().default('Paul Willard'),
    image: z.string().optional(),
    categories: z.array(z.string()).optional(),
    excerpt: z.string().optional(),
  }),
});

export const collections = { updates };
